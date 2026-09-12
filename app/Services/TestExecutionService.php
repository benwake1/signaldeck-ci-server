<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Services;

use App\DTOs\TestExecutionResult;
use App\Support\ChromiumBinaryResolver;
use App\Support\UrlSafetyValidator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Headlessly runs a single generated Playwright or Cypress spec against a
 * real target URL and reports pass/fail — no git clone, no npm install, no
 * browser install. Reuses the app's root node_modules install (both
 * Playwright and Cypress are root dependencies) and cached browser
 * binaries, the same way SiteCrawlerService reuses them for one-off
 * crawls. Not for running a full suite — see RunPlaywrightTestJob /
 * RunCypressTestJob for that.
 */
class TestExecutionService
{
    private const TIMEOUT_SECONDS = 120;

    /**
     * @param array<string, string> $env Extra environment variables for the spawned process
     *   (e.g. a suite's env_variables during a repair-verification run). Never needed for a
     *   fresh interactive-builder generation, since no suite exists yet at that point.
     */
    public function runSpec(string $code, string $fileName, string $targetUrl, string $framework = 'playwright', array $env = []): TestExecutionResult
    {
        UrlSafetyValidator::validate($targetUrl);

        $scratchDir = storage_path('app/test-verify/' . (string) Str::ulid());
        File::ensureDirectoryExists($scratchDir);

        try {
            return $framework === 'cypress'
                ? $this->runCypressSpec($code, $fileName, $targetUrl, $scratchDir, $env)
                : $this->runPlaywrightSpec($code, $fileName, $targetUrl, $scratchDir, $env);
        } catch (\Throwable $e) {
            // A hung browser or a slow multi-page flow can exceed
            // TIMEOUT_SECONDS, which Process::run() surfaces as a thrown
            // ProcessTimedOutException rather than a normal failed result.
            // Left uncaught, this crashes the verification job outright —
            // verification_status never resolves, which (per saveAsSuite's
            // pending-guard) leaves the suite unsavable, and for a repair,
            // leaves the admin unnotified — both indefinitely.
            Log::warning('Test verification run crashed unexpectedly', [
                'framework' => $framework,
                'target_url' => $targetUrl,
                'error' => $e->getMessage(),
            ]);
            return TestExecutionResult::crashed($e->getMessage());
        } finally {
            File::deleteDirectory($scratchDir);
        }
    }

    private function runPlaywrightSpec(string $code, string $fileName, string $targetUrl, string $scratchDir, array $env): TestExecutionResult
    {
        $specFile = $scratchDir . '/' . basename($fileName);
        File::put($specFile, $code);

        $configFile = $scratchDir . '/playwright.config.ts';
        File::put($configFile, $this->buildPlaywrightConfig($targetUrl));

        $reportFile = $scratchDir . '/report.json';

        $playwrightBin = base_path('node_modules/.bin/playwright');
        if (!File::exists($playwrightBin)) {
            return TestExecutionResult::crashed(
                'Playwright is not installed in the application\'s node_modules. Run `npm install` at the project root.'
            );
        }

        $result = Process::path($scratchDir)
            ->timeout(self::TIMEOUT_SECONDS)
            // Internal plumbing must win over a suite's own env vars here — if a
            // suite happened to define a var with this exact name, letting it
            // through would silently redirect the report file and break parsing.
            ->env(['PLAYWRIGHT_JSON_OUTPUT_NAME' => $reportFile] + $env)
            ->run([
                $playwrightBin,
                'test',
                $specFile,
                '--config=' . $configFile,
                '--reporter=json',
            ]);

        if (!File::exists($reportFile)) {
            Log::warning('Test verification run produced no report', [
                'exit_code' => $result->exitCode(),
                'output' => $result->output(),
                'error_output' => $result->errorOutput(),
            ]);
            return TestExecutionResult::crashed(
                trim($result->errorOutput() . "\n" . $result->output()) ?: 'The test run crashed before producing a report.'
            );
        }

        $report = json_decode(File::get($reportFile), true);

        return $this->parsePlaywrightReport($report, $result->exitCode() === 0);
    }

    private function runCypressSpec(string $code, string $fileName, string $targetUrl, string $scratchDir, array $env): TestExecutionResult
    {
        $specFile = $scratchDir . '/' . basename($fileName);
        File::put($specFile, $code);
        File::put($scratchDir . '/cypress.config.cjs', $this->buildCypressConfig($targetUrl, $specFile));

        $cypressBin = base_path('node_modules/.bin/cypress');
        if (!File::exists($cypressBin)) {
            return TestExecutionResult::crashed(
                'Cypress is not installed in the application\'s node_modules. Run `npm install` at the project root.'
            );
        }

        $nodePath = env('NODE_PATH', 'node');
        $scriptPath = resource_path('scripts/run-cypress-spec.cjs');
        $browser = ChromiumBinaryResolver::resolve();

        $args = [$nodePath, $scriptPath, $specFile, $scratchDir];
        if ($browser) {
            $args[] = $browser;
        }

        $result = Process::timeout(self::TIMEOUT_SECONDS)->env($env)->run($args);

        $resultFile = $scratchDir . '/cypress-result.json';
        if (!File::exists($resultFile)) {
            Log::warning('Cypress verification run produced no report', [
                'exit_code' => $result->exitCode(),
                'output' => $result->output(),
                'error_output' => $result->errorOutput(),
            ]);
            return TestExecutionResult::crashed(
                trim($result->errorOutput() . "\n" . $result->output()) ?: 'The test run crashed before producing a report.'
            );
        }

        $report = json_decode(File::get($resultFile), true);

        if (!is_array($report)) {
            return TestExecutionResult::crashed('The test run produced an unreadable report.');
        }

        return $this->parseCypressReport($report);
    }

    private function buildPlaywrightConfig(string $targetUrl): string
    {
        $baseUrl = addslashes($targetUrl);

        // A recorded flow can walk several real pages in one test, each a
        // full navigation on a live (not synthetic) site — Playwright's
        // 30s defaults for both the overall test and each waitForURL/click
        // are tuned for a single fast action and time out well before a
        // multi-page flow finishes, failing every repair attempt the same
        // way since the generated code was never actually the problem.
        return <<<TS
        import { defineConfig } from '@playwright/test';

        export default defineConfig({
            timeout: 90000,
            use: {
                baseURL: '{$baseUrl}',
                headless: true,
                navigationTimeout: 45000,
                actionTimeout: 15000,
            },
        });
        TS;
    }

    private function buildCypressConfig(string $targetUrl, string $specFile): string
    {
        $baseUrl = addslashes($targetUrl);
        $spec = addslashes($specFile);

        return <<<CJS
        module.exports = {
            e2e: {
                baseUrl: '{$baseUrl}',
                supportFile: false,
                specPattern: '{$spec}',
            },
            video: false,
            screenshotOnRunFailure: false,
            defaultCommandTimeout: 10000,
        };
        CJS;
    }

    private function parsePlaywrightReport(?array $report, bool $exitedCleanly): TestExecutionResult
    {
        if (!is_array($report)) {
            return TestExecutionResult::crashed('The test run produced an unreadable report.');
        }

        $failedTests = [];
        $lines = [];

        foreach ($report['suites'] ?? [] as $suite) {
            $this->walkPlaywrightSuite($suite, $failedTests, $lines);
        }

        $passed = $exitedCleanly && empty($failedTests);

        return new TestExecutionResult(
            passed: $passed,
            output: implode("\n", $lines),
            failedTests: $failedTests,
        );
    }

    private function walkPlaywrightSuite(array $suite, array &$failedTests, array &$lines): void
    {
        foreach ($suite['suites'] ?? [] as $nested) {
            $this->walkPlaywrightSuite($nested, $failedTests, $lines);
        }

        foreach ($suite['specs'] ?? [] as $spec) {
            foreach ($spec['tests'] ?? [] as $test) {
                foreach ($test['results'] ?? [] as $testResult) {
                    $status = $testResult['status'] ?? 'unknown';
                    $title = $spec['title'] ?? 'untitled test';

                    if ($status !== 'passed') {
                        $errorMessage = $testResult['error']['message']
                            ?? ($testResult['errors'][0]['message'] ?? 'Unknown failure');
                        $errorMessage = preg_replace('/\x1B\[[0-9;]*[a-zA-Z]/', '', $errorMessage);

                        $failedTests[] = ['title' => $title, 'status' => $status, 'error' => $errorMessage];
                        $lines[] = "FAIL: {$title}\n{$errorMessage}";
                    } else {
                        $lines[] = "PASS: {$title}";
                    }
                }
            }
        }
    }

    private function parseCypressReport(array $report): TestExecutionResult
    {
        if (!empty($report['failedToStart'])) {
            return TestExecutionResult::crashed($report['message'] ?? 'Cypress failed to start.');
        }

        $failedTests = [];
        $lines = [];

        foreach ($report['runs'] ?? [] as $run) {
            foreach ($run['tests'] ?? [] as $test) {
                $title = implode(' > ', $test['title'] ?? ['untitled test']);
                $state = $test['state'] ?? 'unknown';

                if ($state !== 'passed') {
                    $errorMessage = $test['displayError'] ?? 'Unknown failure';
                    $errorMessage = preg_replace('/\x1B\[[0-9;]*[a-zA-Z]/', '', $errorMessage);

                    $failedTests[] = ['title' => $title, 'status' => $state, 'error' => $errorMessage];
                    $lines[] = "FAIL: {$title}\n{$errorMessage}";
                } else {
                    $lines[] = "PASS: {$title}";
                }
            }
        }

        $passed = empty($failedTests) && (int) ($report['totalFailed'] ?? 0) === 0;

        return new TestExecutionResult(
            passed: $passed,
            output: implode("\n", $lines),
            failedTests: $failedTests,
        );
    }
}
