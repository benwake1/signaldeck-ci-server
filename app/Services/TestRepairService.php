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
use App\Models\ManagedTestFile;
use App\Models\TestRun;
use App\Models\TestSuite;
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

    public function attemptRepair(TestSuite $suite): void
    {
        // A health breach fires off a rolling 10-run pass rate, which can stay
        // below threshold for a while after the underlying problem is already
        // fixed (e.g. a flaky test that passed on retry, or a manual fix).
        // Repairing against an old failure in that case would work from stale
        // context, so require the suite's most recent run overall — not just
        // its most recent failure — to actually be the thing that's broken.
        $failedRun = $suite->latest_run;

        if (!$failedRun || !in_array($failedRun->status, [TestRun::STATUS_FAILED, TestRun::STATUS_ERROR], true)) {
            Log::info('Skipping automated repair: suite\'s most recent run is not currently failing', [
                'suite_id' => $suite->id,
                'latest_run_status' => $failedRun?->status,
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

        $conversation->update(['verification_status' => VerificationStatus::Pending]);

        Bus::chain([
            new VerifyGeneratedTestJob($conversation, $result->files, $framework, $suite->getMergedEnvVariablesAttribute()),
            new NotifyRepairCompletedJob($conversation),
        ])->dispatch();
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

        return "This suite's most recent run in production failed (status: {$run->status}). "
            . "Fix the code so it passes — keep the same test intent, selectors, and file paths.\n\n"
            . "Run output:\n{$output}";
    }
}
