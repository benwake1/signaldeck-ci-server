<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Jobs;

use App\Events\TestRunStatusChanged;
use App\Jobs\Concerns\RunsTestSuite;
use App\Models\TestRun;
use App\Services\MochawesomeParserService;
use App\Services\ReportGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RunCypressTestJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, RunsTestSuite;

    public int $timeout;
    public int $tries = 1;

    private bool $syntheticReport = false;

    public function __construct(
        public readonly TestRun $run
    ) {
        $this->onQueue('cypress');
        $this->timeout = (int) config('testing.job_timeout', 10800);
        $this->initRunPath();
    }

    public function handle(
        MochawesomeParserService $parser,
        ReportGeneratorService $reporter
    ): void {
        try {
            $this->updateStatus(TestRun::STATUS_CLONING);

            $this->cloneRepo();
            $this->checkCancelled();

            $this->updateStatus(TestRun::STATUS_INSTALLING);

            $this->installDependencies();
            $this->checkCancelled();

            $this->buildTailwind();
            $this->checkCancelled();

            $this->updateStatus(TestRun::STATUS_RUNNING);
            $this->run->update(['started_at' => now()]);

            $exitCode = $this->runCypress();

            $this->log('📊 Merging test reports...');
            $mergedJsonPath = $this->mergeMochawesomeReports();

            $storedJsonPath = "reports/run-{$this->run->id}/merged.json";
            Storage::disk('local')->put($storedJsonPath, file_get_contents($mergedJsonPath));
            $this->run->update(['merged_json_path' => $storedJsonPath]);

            $this->log('💾 Storing test results...');
            $parser->parse($this->run->fresh(), $mergedJsonPath);

            if ($this->syntheticReport) {
                $this->run->update(['status' => TestRun::STATUS_FAILED]);
            }

            $this->log('🎬 Processing artifacts...');
            $parser->mapVideosToResults($this->run->fresh(), $this->runPath . '/cypress/videos');
            $parser->mapScreenshotsToResults($this->run->fresh(), $this->runPath . '/cypress/screenshots');

            $this->log('📄 Generating branded HTML report...');
            $reporter->generateHtmlReport($this->run->fresh());

            $freshRun = $this->run->fresh();
            $this->log($freshRun->status === TestRun::STATUS_PASSING
                ? "✅ All {$freshRun->passed_tests} tests passed!"
                : "❌ {$freshRun->failed_tests} of {$freshRun->total_tests} tests failed."
            );

            event(new TestRunStatusChanged($this->run->fresh()));

        } catch (\Exception $e) {
            if ($this->run->fresh()->status === TestRun::STATUS_CANCELLED) {
                $this->log('🛑 Run was cancelled.');
                return;
            }

            Log::error('Cypress run failed', [
                'run_id' => $this->run->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->run->update([
                'status' => TestRun::STATUS_ERROR,
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $this->log('💥 Error: ' . $e->getMessage());
            event(new TestRunStatusChanged($this->run->fresh()));
        } finally {
            $this->cleanup();
        }
    }

    private function runCypress(): int
    {
        $suite = $this->run->testSuite;
        $envString = $this->buildEnvString();

        $specPattern = $this->run->spec_override ?? $suite->spec_pattern;
        $reporterFlags = '--reporter mochawesome --reporter-options "reportDir=mochawesome-report,overwrite=false,html=false,json=true"';
        $browser = $this->resolveChromiumBinary();
        $browserFlag = $browser ? '--browser ' . escapeshellarg($browser) : '';
        $configFlags = '--config experimentalMemoryManagement=true,numTestsKeptInMemory=0,videoCompression=20';
        // xvfb-run is required on headless Linux (Docker). On macOS (local dev) it
        // doesn't exist, so we detect it at runtime and omit the prefix there.
        $xvfb = trim((string) shell_exec('which xvfb-run 2>/dev/null'));
        $xvfbPrefix = $xvfb ? "xvfb-run --auto-servernum --server-args='-screen 0 1920x1080x24' " : '';
        $cmd = 'cd ' . escapeshellarg($this->runPath) . " && {$xvfbPrefix}{$envString} npx cypress run --spec " . escapeshellarg($specPattern) . " {$reporterFlags} {$configFlags} {$browserFlag} 2>&1";

        $this->log("🧪 Running Cypress tests...");
        $this->log("   Spec pattern: {$specPattern}");

        return $this->streamProcess($cmd);
    }

    private function resolveChromiumBinary(): ?string
    {
        foreach (['/usr/local/bin/chrome-cypress', '/usr/bin/google-chrome-stable'] as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }

    private function mergeMochawesomeReports(): string
    {
        $reportDir = $this->runPath . '/mochawesome-report';
        $mergedPath = $this->runPath . '/merged.json';

        if (!is_dir($reportDir)) {
            mkdir($reportDir, 0755, true);
            $syntheticReport = [
                'stats' => ['suites' => 0, 'tests' => 0, 'passes' => 0, 'pending' => 0, 'failures' => 1, 'start' => now()->toIso8601String(), 'end' => now()->toIso8601String(), 'duration' => 0],
                'results' => [],
                'meta' => ['mocha' => ['version' => 'unknown'], 'mochawesome' => ['version' => 'unknown'], 'marge' => ['version' => 'unknown']],
            ];
            file_put_contents($reportDir . '/mochawesome.json', json_encode($syntheticReport));
            $this->log('⚠️ Mochawesome report not found — Cypress may have crashed before writing results.');
            $this->syntheticReport = true;
        }

        $jsonFiles = glob($reportDir . '/*.json');
        if (empty($jsonFiles)) {
            throw new \RuntimeException("No mochawesome JSON files found in {$reportDir}");
        }

        $this->exec('cd ' . escapeshellarg($this->runPath) . ' && npx mochawesome-merge mochawesome-report/*.json -o merged.json 2>&1');

        if (!file_exists($mergedPath)) {
            throw new \RuntimeException("Failed to create merged.json");
        }

        return $mergedPath;
    }

    public function failed(\Throwable $exception): void
    {
        $this->run->update([
            'status' => TestRun::STATUS_ERROR,
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
