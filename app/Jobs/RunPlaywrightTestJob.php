<?php

namespace App\Jobs;

use App\Events\TestRunStatusChanged;
use App\Jobs\Concerns\RunsTestSuite;
use App\Models\TestRun;
use App\Services\PlaywrightParserService;
use App\Services\ReportGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RunPlaywrightTestJob implements ShouldQueue
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
        PlaywrightParserService $parser,
        ReportGeneratorService $reporter
    ): void {
        try {
            $this->updateStatus(TestRun::STATUS_CLONING);

            $this->cloneRepo();
            $this->checkCancelled();

            $this->updateStatus(TestRun::STATUS_INSTALLING);

            $this->installDependencies();
            $this->checkCancelled();

            $this->installPlaywrightBrowsers();
            $this->checkCancelled();

            $this->buildTailwind();
            $this->checkCancelled();

            $this->updateStatus(TestRun::STATUS_RUNNING);
            $this->run->update(['started_at' => now()]);

            $exitCode = $this->runPlaywright();

            // Store the JSON report on the private disk
            $jsonPath = $this->runPath . '/results.json';
            if (file_exists($jsonPath)) {
                $storedJsonPath = "reports/run-{$this->run->id}/merged.json";
                Storage::disk('local')->put($storedJsonPath, file_get_contents($jsonPath));
                $this->run->update(['merged_json_path' => $storedJsonPath]);

                $this->log('💾 Storing test results...');
                $parser->parse($this->run->fresh(), $jsonPath);
            } else {
                $this->log('⚠️ Playwright JSON report not found — tests may have crashed before writing results.');
                $this->syntheticReport = true;
                $this->run->update([
                    'status'      => TestRun::STATUS_FAILED,
                    'finished_at' => now(),
                ]);
            }

            // Map artifacts from Playwright's test-results directory
            $this->log('🎬 Processing artifacts...');
            $parser->mapArtifacts($this->run->fresh(), $this->runPath . '/test-results');

            // Generate branded HTML report
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

            Log::error('Playwright run failed', [
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

    private function installPlaywrightBrowsers(): void
    {
        $this->log('🌐 Installing Playwright browsers...');
        $this->exec('cd ' . escapeshellarg($this->runPath) . ' && npx playwright install --with-deps 2>&1');
        $this->log('✅ Playwright browsers installed.');
    }

    private function runPlaywright(): int
    {
        $suite = $this->run->testSuite;
        $envString = $this->buildEnvString();

        // Write JSON output to a file so stdout is free for real-time log streaming
        $jsonOutputPath = $this->runPath . '/results.json';
        $envString .= 'PLAYWRIGHT_JSON_OUTPUT_NAME=' . escapeshellarg($jsonOutputPath) . ' ';

        // Build spec file args for re-run-failures (specific file paths only).
        // For normal runs, Playwright discovers tests via its own config (testDir/testMatch),
        // so we don't pass the suite's spec_pattern — it's a glob that Playwright would
        // misinterpret as a grep regex pattern.
        $specArgs = '';
        if ($this->run->spec_override) {
            // spec_override is comma-separated file paths for re-running specific specs
            $specs = array_map('trim', explode(',', $this->run->spec_override));
            foreach ($specs as $spec) {
                $specArgs .= ' ' . escapeshellarg($spec);
            }
        }

        // Build --project flags from suite's playwright_projects
        $projectFlags = '';
        $projects = $suite->playwright_projects ?? [];
        foreach ($projects as $project) {
            $projectFlags .= ' --project ' . escapeshellarg($project);
        }

        // Performance tuning flags (override playwright.config.ts via CLI)
        $tuningFlags = '';
        if ($suite->playwright_workers) {
            $tuningFlags .= ' --workers=' . (int) $suite->playwright_workers;
        }
        if ($suite->playwright_retries !== null) {
            $tuningFlags .= ' --retries=' . (int) $suite->playwright_retries;
        }

        // Use line reporter for human-readable stdout + json for structured output
        $cmd = 'cd ' . escapeshellarg($this->runPath)
            . " && {$envString} npx playwright test"
            . ' --reporter=line,json'
            . $tuningFlags
            . $specArgs
            . $projectFlags
            . ' 2>&1';

        $this->log('🧪 Running Playwright tests...');
        if ($this->run->spec_override) {
            $this->log("   Specs: {$this->run->spec_override}");
        }
        if (!empty($projects)) {
            $this->log('   Projects: ' . implode(', ', $projects));
        }

        return $this->streamProcess($cmd);
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
