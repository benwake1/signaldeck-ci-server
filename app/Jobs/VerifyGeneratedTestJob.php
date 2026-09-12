<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Jobs;

use App\Enums\VerificationStatus;
use App\Models\AiConversation;
use App\Services\AiTestGeneratorService;
use App\Services\TestExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs a just-generated/refined Playwright or Cypress test against the real
 * target site. On failure, feeds the error back to the AI for a bounded
 * number of automatic fixes before giving up and flagging the result
 * unverified.
 */
class VerifyGeneratedTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    private const MAX_ATTEMPTS = 3;

    /**
     * @param array<string, string> $files Path => code, for every file from this generation turn.
     * @param array<string, string> $env Extra environment variables for the verification run
     *   (e.g. a suite's merged env vars during a repair-triggered verification).
     */
    public function __construct(
        public readonly AiConversation $conversation,
        public readonly array $files,
        public readonly string $framework,
        public readonly array $env = [],
    ) {}

    public function handle(TestExecutionService $executor, AiTestGeneratorService $generator): void
    {
        $conversation = $this->conversation->fresh();
        if (!$conversation) {
            return;
        }

        if (!in_array($this->framework, ['playwright', 'cypress'], true)) {
            $conversation->update(['verification_status' => VerificationStatus::SkippedUnsupportedFramework]);
            return;
        }

        $hasExtraDependencies = collect(array_keys($this->files))
            ->contains(fn (string $path) => str_ends_with($path, 'package.json'));

        if ($hasExtraDependencies) {
            $conversation->update(['verification_status' => VerificationStatus::SkippedExtraDependencies]);
            return;
        }

        $filesToVerify = collect($this->files)
            ->filter(fn (string $code, string $path) => $this->isTestFile($path))
            ->all();

        if (empty($filesToVerify)) {
            $conversation->update(['verification_status' => VerificationStatus::SkippedNoTestFiles]);
            return;
        }

        $targetUrl = $this->resolveTargetUrl($conversation);
        if (!$targetUrl) {
            $conversation->update(['verification_status' => VerificationStatus::SkippedNoUrl]);
            return;
        }

        $currentFiles = $filesToVerify;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $failures = [];

            foreach ($currentFiles as $path => $code) {
                $result = $executor->runSpec($code, basename($path), $targetUrl, $this->framework, $this->env);
                if (!$result->passed) {
                    $failures[$path] = $result->output;
                }
            }

            if (empty($failures)) {
                $conversation->update([
                    'verification_status' => VerificationStatus::Passed,
                    'verification_output' => 'All ' . count($currentFiles) . ' verification run(s) passed against ' . $targetUrl . '.',
                ]);
                return;
            }

            if ($attempt === self::MAX_ATTEMPTS) {
                $conversation->update([
                    'verification_status' => VerificationStatus::FailedUnverified,
                    'verification_output' => collect($failures)
                        ->map(fn (string $output, string $path) => "{$path}:\n{$output}")
                        ->implode("\n\n"),
                ]);
                return;
            }

            try {
                $feedback = $this->buildRepairFeedback($failures);
                $genResult = $generator->refine($conversation, $feedback, $this->framework);

                foreach (array_keys($failures) as $path) {
                    if (isset($genResult->files[$path])) {
                        $currentFiles[$path] = $genResult->files[$path];
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Automatic repair attempt failed', [
                    'conversation_id' => $conversation->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);
                $conversation->update([
                    'verification_status' => VerificationStatus::FailedUnverified,
                    'verification_output' => collect($failures)
                        ->map(fn (string $output, string $path) => "{$path}:\n{$output}")
                        ->implode("\n\n"),
                ]);
                return;
            }
        }
    }

    /**
     * Matches the extension conventions from both frameworks' configured
     * default_spec_pattern (config/testing.php): Playwright's
     * tests/**\/*.spec.{js,ts} and Cypress's cypress/e2e/**\/*.cy.{js,jsx,ts,tsx}.
     * A narrower allowlist here silently drops real test files from
     * verification, leaving the conversation stuck at "pending" forever
     * (and, since saveAsSuite() now blocks while pending, unsavable).
     */
    private function isTestFile(string $path): bool
    {
        return (bool) preg_match('/\.(spec\.(js|ts)|cy\.(js|jsx|ts|tsx))$/', $path);
    }

    private function buildRepairFeedback(array $failures): string
    {
        $lines = [
            'The following generated test(s) were actually run against the live site and failed. '
            . 'Fix the code so it passes — keep the same test intent, selectors from the crawl/recording data where possible, and file path(s).',
        ];

        foreach ($failures as $path => $output) {
            $lines[] = "\nFile: {$path}\nFailure output:\n{$output}";
        }

        return implode("\n", $lines);
    }

    private function resolveTargetUrl(AiConversation $conversation): ?string
    {
        $crawlUrl = $conversation->crawl_data['url'] ?? null;
        if ($crawlUrl) {
            return $crawlUrl;
        }

        $recording = $conversation->recording_data;
        if (!empty($recording) && isset($recording[0]['url'])) {
            return $recording[0]['url'];
        }

        return null;
    }
}
