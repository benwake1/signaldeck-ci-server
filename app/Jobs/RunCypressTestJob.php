<?php

namespace App\Jobs;

use App\Events\TestRunLogReceived;
use App\Events\TestRunStatusChanged;
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
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $timeout;
    public int $tries = 1;

    private string $runPath;
    private bool $syntheticReport = false;

    public function __construct(
        public readonly TestRun $run
    ) {
        $this->onQueue('cypress');
        $this->timeout = (int) config('cypress.job_timeout', 10800); // default 3 hours
        $this->runPath = sys_get_temp_dir() . "/cypress-runs/{$run->id}";
    }

    public function handle(
        MochawesomeParserService $parser,
        ReportGeneratorService $reporter
    ): void {
        try {
            $this->updateStatus(TestRun::STATUS_CLONING);

            // 1. Clone the repository
            $this->cloneRepo();
            $this->checkCancelled();

            $this->updateStatus(TestRun::STATUS_INSTALLING);

            // 2. Install npm dependencies
            $this->installDependencies();
            $this->checkCancelled();

            // 3. Build Tailwind (for report styling) if script exists
            $this->buildTailwind();
            $this->checkCancelled();

            $this->updateStatus(TestRun::STATUS_RUNNING);
            $this->run->update(['started_at' => now()]);

            // 4. Run Cypress
            $exitCode = $this->runCypress();

            // 5. Merge mochawesome JSON files
            $this->log('📊 Merging test reports...');
            $mergedJsonPath = $this->mergeMochawesomeReports();

            // 6. Store the merged JSON on the private disk — it contains test titles,
            //    error messages, and stack traces that should not be publicly accessible.
            $storedJsonPath = "reports/run-{$this->run->id}/merged.json";
            Storage::disk('local')->put($storedJsonPath, file_get_contents($mergedJsonPath));
            $this->run->update(['merged_json_path' => $storedJsonPath]);

            // 7. Parse results into DB
            $this->log('💾 Storing test results...');
            $parser->parse($this->run->fresh(), $mergedJsonPath);

            // If mochawesome didn't write a report (e.g. renderer crash), the synthetic
            // report has no results so mochawesome-merge outputs 0 failures. Force failed.
            if ($this->syntheticReport) {
                $this->run->update(['status' => TestRun::STATUS_FAILED]);
            }

            // 8. Map videos and screenshots
            $this->log('🎬 Processing artifacts...');
            $parser->mapVideosToResults($this->run->fresh(), $this->runPath . '/cypress/videos');
            $parser->mapScreenshotsToResults($this->run->fresh(), $this->runPath . '/cypress/screenshots');

            // 9. Generate branded HTML report
            $this->log('📄 Generating branded HTML report...');
            $reporter->generateHtmlReport($this->run->fresh());

            $freshRun = $this->run->fresh();
            $this->log($freshRun->status === TestRun::STATUS_PASSING
                ? "✅ All {$freshRun->passed_tests} tests passed!"
                : "❌ {$freshRun->failed_tests} of {$freshRun->total_tests} tests failed."
            );

            event(new TestRunStatusChanged($this->run->fresh()));

        } catch (\Exception $e) {
            // Don't overwrite a deliberate cancellation with an error status
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

    private function cloneRepo(): void
    {
        $project = $this->run->project;

        if (is_dir($this->runPath)) {
            $this->exec('rm -rf ' . escapeshellarg($this->runPath));
        }
        mkdir($this->runPath, 0755, true);

        $branch  = $this->run->branch;
        $repoUrl = $project->repo_url;

        // Set up SSH key if provided
        $sshKeyPath = null;
        if ($project->deploy_key_private) {
            $sshKeyPath    = $this->setupSshKey($project->deploy_key_private);
            // accept-new: auto-accepts genuinely new host keys but refuses if key changes (prevents MITM).
            $gitSshCommand = 'GIT_SSH_COMMAND=' . escapeshellarg("ssh -i {$sshKeyPath} -o StrictHostKeyChecking=accept-new");
        } else {
            $gitSshCommand = '';
        }

        $this->log("🔄 Cloning {$repoUrl} (branch: {$branch})...");

        try {
            $cloneCmd = "{$gitSshCommand} git clone --depth 1 --branch " . escapeshellarg($branch) . ' ' . escapeshellarg($repoUrl) . ' ' . escapeshellarg($this->runPath) . ' 2>&1';
            $this->exec($cloneCmd);

            // Get commit SHA
            $sha = trim($this->exec('git -C ' . escapeshellarg($this->runPath) . ' rev-parse HEAD 2>&1'));
            if (strlen($sha) === 40) {
                $this->run->update(['commit_sha' => substr($sha, 0, 8)]);
            }
        } finally {
            // Always remove the key file — even if the clone throws an exception.
            if ($sshKeyPath && file_exists($sshKeyPath)) {
                unlink($sshKeyPath);
            }
        }

        $this->log("✅ Repository cloned successfully.");
    }

    private function setupSshKey(string $privateKey): string
    {
        $keyPath = tempnam(sys_get_temp_dir(), 'cypress_ssh_');
        file_put_contents($keyPath, $privateKey);
        chmod($keyPath, 0600);
        return $keyPath;
    }

    private function installDependencies(): void
    {
        $this->log("📦 Installing npm dependencies...");
        $this->exec('cd ' . escapeshellarg($this->runPath) . ' && npm install --prefer-offline 2>&1');
        $this->log("✅ Dependencies installed.");
    }

    private function buildTailwind(): void
    {
        $packageJsonPath = $this->runPath . '/package.json';
        if (!file_exists($packageJsonPath)) return;

        $packageJson = json_decode(file_get_contents($packageJsonPath), true);
        $scripts = $packageJson['scripts'] ?? [];

        if (isset($scripts['build:tailwind'])) {
            $this->log("🎨 Building Tailwind CSS...");
            try {
                $this->exec('cd ' . escapeshellarg($this->runPath) . ' && npm run build:tailwind 2>&1');
            } catch (\RuntimeException $e) {
                $this->log("⚠️ Tailwind build skipped: " . $e->getMessage());
            }
        }
    }

    private function runCypress(): int
    {
        $suite = $this->run->testSuite;
        $project = $this->run->project;

        // Build env string from project + suite env vars
        $envVars = array_merge(
            $project->env_variables,
            $suite->env_variables
        );

        $envString = '';
        foreach ($envVars as $key => $value) {
            // Only allow valid shell variable names to prevent injection via keys
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $envString .= "{$key}=" . escapeshellarg($value) . ' ';
        }

        // Build cypress command — spec_override targets only failing specs from a previous run
        $specPattern = $this->run->spec_override ?? $suite->spec_pattern;
        // Force mochawesome reporter via CLI so we don't depend on each client repo having it configured
        $reporterFlags = '--reporter mochawesome --reporter-options "reportDir=mochawesome-report,overwrite=false,html=false,json=true"';
        // Prefer Chromium over Electron — Electron has IPC sandbox issues on Linux servers
        $browser = $this->resolveChromiumBinary();
        $browserFlag = $browser ? '--browser ' . escapeshellarg($browser) : '';
        // Reduce memory pressure to prevent renderer crashes on memory-intensive apps
        $configFlags = '--config experimentalMemoryManagement=true,numTestsKeptInMemory=0,videoCompression=20';
        // Merge stderr into stdout so we capture everything on one pipe
        $cmd = 'cd ' . escapeshellarg($this->runPath) . " && {$envString} npx cypress run --spec " . escapeshellarg($specPattern) . " {$reporterFlags} {$configFlags} {$browserFlag} 2>&1";

        $this->log("🧪 Running Cypress tests...");
        $this->log("   Spec pattern: {$specPattern}");

        // Run cypress and stream output
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start Cypress process');
        }

        fclose($pipes[0]);

        $fullLog = '';
        stream_set_blocking($pipes[1], false);

        while (true) {
            // Check for cancellation each tick
            if ($this->run->fresh()->status === TestRun::STATUS_CANCELLED) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                proc_close($process);
                $this->run->update(['log_output' => $fullLog]);
                throw new \RuntimeException('Run cancelled by user.');
            }

            $read = [$pipes[1]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 1) > 0) {
                $line = fgets($pipes[1]);
                if ($line !== false) {
                    $fullLog .= $line;
                    $this->log(rtrim($line));
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                // Drain remaining output
                $remaining = stream_get_contents($pipes[1]);
                if ($remaining) {
                    $fullLog .= $remaining;
                    foreach (explode("\n", $remaining) as $line) {
                        if (trim($line)) $this->log($line);
                    }
                }
                break;
            }
        }

        fclose($pipes[1]);
        $exitCode = proc_close($process);

        // Append full log to run
        $this->run->update(['log_output' => $fullLog]);

        return $exitCode;
    }

    private function resolveChromiumBinary(): ?string
    {
        // Prefer the wrapper script which adds server-optimised Chrome flags
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
            // Renderer crash or early Cypress failure — no report was written.
            // Create a minimal failed report so the run can be stored rather than erroring out.
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

    private function checkCancelled(): void
    {
        if ($this->run->fresh()->status === TestRun::STATUS_CANCELLED) {
            throw new \RuntimeException('Run cancelled by user.');
        }
    }

    private function updateStatus(string $status): void
    {
        $this->run->update(['status' => $status]);
        broadcast(new TestRunStatusChanged($this->run->fresh()));
    }

    private function log(string $message): void
    {
        $timestamp = now()->format('H:i:s');
        $logLine = "[{$timestamp}] {$message}";

        broadcast(new TestRunLogReceived($this->run->id, $logLine));

        Log::info("Run #{$this->run->id}: {$message}");
    }

    private function exec(string $command): string
    {
        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);
        $result = implode("\n", $output);

        if ($returnCode !== 0) {
            throw new \RuntimeException("Command failed (exit {$returnCode}): {$result}");
        }

        return $result;
    }

    private function cleanup(): void
    {
        if (is_dir($this->runPath)) {
            exec('rm -rf ' . escapeshellarg($this->runPath));
        }
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
