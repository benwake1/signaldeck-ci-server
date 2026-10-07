<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\VerificationStatus;
use App\Jobs\NotifyRepairCompletedJob;
use App\Jobs\VerifyGeneratedTestJob;
use App\Models\AiConversation;
use App\Models\AppSetting;
use App\Models\ManagedTestFile;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Support\AssertionDiff;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Proposes an AI-generated fix for an unhealthy managed suite, using the same
 * generate-then-verify machinery as the interactive builder. Never writes to
 * ManagedTestFile — the result is a conversation a human reviews and can
 * accept via the existing "Update Suite" action.
 */
class TestRepairService
{
    private const MAX_FEEDBACK_OUTPUT_LENGTH = 8000;

    public const MIN_CONSECUTIVE_FAILURES = 3;

    public const ASSESSMENT_TEST_DRIFT = 'test_drift';
    public const ASSESSMENT_APP_REGRESSION = 'app_regression';
    public const ASSESSMENT_UNCLEAR = 'unclear';

    public static function requiredConsecutiveFailures(): int
    {
        return max(self::MIN_CONSECUTIVE_FAILURES, (int) AppSetting::get('ai_auto_repair_consecutive_failures', self::MIN_CONSECUTIVE_FAILURES));
    }

    /**
     * The latest failed run if the suite's last N completed runs are all test
     * failures, otherwise null. A health breach fires off a rolling 10-run pass
     * rate, which can stay low long after a one-off failure or a manual fix, so
     * this demands a sustained, current failure streak. Runs that errored
     * (clone/install/infra problems) break the streak — those aren't something
     * editing test code can or should fix.
     */
    public function failingRunForRepair(TestSuite $suite): ?TestRun
    {
        $required = self::requiredConsecutiveFailures();

        $recent = $suite->testRuns()
            ->whereIn('status', [TestRun::STATUS_PASSING, TestRun::STATUS_FAILED, TestRun::STATUS_ERROR])
            ->latest()
            ->orderByDesc('id')
            ->limit($required)
            ->get();

        if ($recent->count() < $required || $recent->contains(fn (TestRun $run) => $run->status !== TestRun::STATUS_FAILED)) {
            return null;
        }

        // An in-flight or newer run outranks the streak — wait for it to finish.
        $latest = $suite->testRuns()->where('status', '!=', TestRun::STATUS_CANCELLED)->latest()->orderByDesc('id')->first();

        return $latest?->is($recent->first()) ? $recent->first() : null;
    }

    public function attemptRepair(TestSuite $suite): void
    {
        $failedRun = $this->failingRunForRepair($suite);

        if (!$failedRun) {
            Log::info('Skipping automated repair: suite does not have a current streak of failed runs', [
                'suite_id' => $suite->id,
                'required' => self::requiredConsecutiveFailures(),
            ]);
            return;
        }

        $managedFiles = $suite->managedTestFiles;
        if ($managedFiles->isEmpty()) {
            Log::info('Skipping automated repair: suite has no managed files', ['suite_id' => $suite->id]);
            return;
        }

        $framework = $suite->getEffectiveRunnerType()->value;

        $conversation = AiConversation::create([
            'project_id' => $suite->project_id,
            'test_suite_id' => $suite->id,
            'title' => "Automated repair: {$suite->name}",
            'framework' => $framework,
            'status' => ConversationStatus::Active,
            'crawl_data' => ['url' => $suite->base_url],
            'messages' => $this->seedMessages($managedFiles),
        ]);

        try {
            $result = app(AiTestGeneratorService::class)->refine(
                $conversation,
                $this->buildFeedback($failedRun),
                $framework
            );
        } catch (\Throwable $e) {
            Log::error('Automated repair generation failed', [
                'suite_id' => $suite->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            $conversation->update(['status' => ConversationStatus::Failed]);
            return;
        }

        [$assessment, $reason] = self::parseAssessment($result->explanation);
        $proposesFix = $assessment === self::ASSESSMENT_TEST_DRIFT && $result->files !== [];

        $diff = $proposesFix
            ? AssertionDiff::compare($managedFiles->pluck('content', 'file_path')->all(), $result->files)
            : ['removed' => [], 'skipped' => []];

        $conversation->update([
            'repair_assessment' => [
                'assessment' => $assessment,
                'reason' => $reason,
                'proposes_fix' => $proposesFix,
                'failed_runs' => self::requiredConsecutiveFailures(),
                'removed_assertions' => $diff['removed'],
                'added_skips' => $diff['skipped'],
            ],
            'verification_status' => $proposesFix ? VerificationStatus::Pending : null,
        ]);

        if (!$proposesFix) {
            NotifyRepairCompletedJob::dispatch($conversation);
            return;
        }

        Bus::chain([
            new VerifyGeneratedTestJob($conversation, $result->files, $framework, $suite->getMergedEnvVariablesAttribute()),
            new NotifyRepairCompletedJob($conversation),
        ])->dispatch();
    }

    /**
     * Pull the "ASSESSMENT: <kind> — <reason>" line the repair prompt asks
     * for. Anything missing or unrecognised is treated as unclear, which
     * means no fix is proposed.
     *
     * @return array{0: string, 1: string}
     */
    public static function parseAssessment(string $explanation): array
    {
        $kinds = implode('|', [self::ASSESSMENT_TEST_DRIFT, self::ASSESSMENT_APP_REGRESSION, self::ASSESSMENT_UNCLEAR]);

        if (!preg_match('/^[\s*_#>]*ASSESSMENT[\s*_]*:[\s*_`]*(' . $kinds . ')[\s*_`]*(?:[-—–:]\s*)?(.*)$/miu', $explanation, $m)) {
            return [self::ASSESSMENT_UNCLEAR, 'The AI did not provide an assessment of the failure.'];
        }

        return [strtolower($m[1]), trim($m[2], " \t*_")];
    }

    /**
     * @param Collection<int, ManagedTestFile> $managedFiles
     * @return array<int, array{role: string, content: string, timestamp: string}>
     */
    private function seedMessages(Collection $managedFiles): array
    {
        $fileContext = "Here are the current test files we're working with:\n\n";
        foreach ($managedFiles as $file) {
            $fileContext .= "```javascript file:{$file->file_path}\n{$file->content}\n```\n\n";
        }

        $now = now()->toIso8601String();

        return [
            ['role' => 'user', 'content' => $fileContext, 'timestamp' => $now],
            ['role' => 'assistant', 'content' => 'Understood. I have the current state of all test files. What would you like me to do?', 'timestamp' => $now],
        ];
    }

    private function buildFeedback(TestRun $run): string
    {
        $output = trim(($run->error_message ? $run->error_message . "\n\n" : '') . ($run->log_output ?? ''));
        $output = $output !== '' ? $output : 'The suite is failing in production but no detailed output was captured for this run.';
        $output = \Illuminate\Support\Str::limit($output, self::MAX_FEEDBACK_OUTPUT_LENGTH, "\n... (truncated)");

        $required = self::requiredConsecutiveFailures();

        return <<<PROMPT
        This suite has failed its last {$required} runs in production. Before changing anything, diagnose why.

        Start your reply with exactly one line in this format:
        ASSESSMENT: <test_drift|app_regression|unclear> — <one-sentence reason>

        - test_drift: the application changed intentionally (renamed selector, moved element, reworded copy, extra step in a flow) and the test is out of date.
        - app_regression: the application itself looks broken (server errors, missing data or content, a feature that no longer works). The test is doing its job by failing.
        - unclear: the output isn't enough to tell.

        If your assessment is app_regression or unclear, do NOT return any code blocks. Explain what you think is wrong so a human can investigate.

        If your assessment is test_drift, return the updated files, keeping the same test intent and file paths. Never remove, weaken, or skip assertions to get a pass: no deleting expect/should checks, no broadening matchers, no .skip, no inflated timeouts, no try/catch or conditionals that swallow failures. Only update selectors, navigation steps, and expected values where the application has clearly and deliberately changed.

        Latest run output:
        {$output}
        PROMPT;
    }
}
