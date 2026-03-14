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

    public int $timeout = 3600; // 1 hour max
    public int $tries = 1;

    private string $runPath;

    public function __construct(
        public readonly TestRun $run
    ) {
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

            $this->updateStatus(TestRun::STATUS_INSTALLING);

            // 2. Install npm dependencies
            $this->installDependencies();

            // 3. Build Tailwind (for report styling) if script exists
            $this->buildTailwind();

            $this->updateStatus(TestRun::STATUS_RUNNING);
            $this->run->update(['started_at' => now()]);

            // 4. Run Cypress
            $exitCode = $this->runCypress();

            // 5. Merge mochawesome JSON files
            $this->log('📊 Merging test reports...');
            $mergedJsonPath = $this->mergeMochawesomeReports();

            // 6. Store the merged JSON
            $storedJsonPath = "reports/run-{$this->run->id}/merged.json";
            Storage::disk('public')->put($storedJsonPath, file_get_contents($mergedJsonPath));
            $this->run->update(['merged_json_path' => $storedJsonPath]);

            // 7. Parse results into DB
            $this->log('💾 Storing test results...');
            $parser->parse($this->run->fresh(), $mergedJsonPath);

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

            broadcast(new TestRunStatusChanged($this->run->fresh()));

        } catch (\Exception $e) {
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
            broadcast(new TestRunStatusChanged($this->run->fresh()));
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
            $gitSshCommand = 'GIT_SSH_COMMAND=' . escapeshellarg("ssh -i {$sshKeyPath} -o StrictHostKeyChecking=no");
        } else {
            $gitSshCommand = '';
        }

        $this->log("🔄 Cloning {$repoUrl} (branch: {$branch})...");

        $cloneCmd = "{$gitSshCommand} git clone --depth 1 --branch " . escapeshellarg($branch) . ' ' . escapeshellarg($repoUrl) . ' ' . escapeshellarg($this->runPath) . ' 2>&1';
        $this->exec($cloneCmd);

        // Get commit SHA
        $sha = trim($this->exec('git -C ' . escapeshellarg($this->runPath) . ' rev-parse HEAD 2>&1'));
        if (strlen($sha) === 40) {
            $this->run->update(['commit_sha' => substr($sha, 0, 8)]);
        }

        if ($sshKeyPath) {
            unlink($sshKeyPath);
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
        $packageJson = json_decode(file_get_contents($this->runPath . '/package.json'), true);
        $scripts = $packageJson['scripts'] ?? [];

        if (isset($scripts['build:tailwind'])) {
            $this->log("🎨 Building Tailwind CSS...");
            $this->exec('cd ' . escapeshellarg($this->runPath) . ' && npm run build:tailwind 2>&1');
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

        // Build cypress command
        $specPattern = $suite->spec_pattern;
        $cmd = 'cd ' . escapeshellarg($this->runPath) . " && {$envString} npx cypress run --spec " . escapeshellarg($specPattern) . ' 2>&1';

        $this->log("🧪 Running Cypress tests...");
        $this->log("   Spec pattern: {$specPattern}");

        // Run cypress and stream output
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start Cypress process');
        }

        fclose($pipes[0]);

        $fullLog = '';
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        while (true) {
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;

            if (stream_select($read, $write, $except, 1) > 0) {
                foreach ($read as $pipe) {
                    $line = fgets($pipe);
                    if ($line !== false) {
                        $fullLog .= $line;
                        $this->log(rtrim($line));
                    }
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                // Drain remaining output
                $remaining = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
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
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        // Append full log to run
        $this->run->update(['log_output' => $fullLog]);

        return $exitCode;
    }

    private function mergeMochawesomeReports(): string
    {
        $reportDir = $this->runPath . '/mochawesome-report';
        $mergedPath = $this->runPath . '/merged.json';

        if (!is_dir($reportDir)) {
            throw new \RuntimeException("Mochawesome report directory not found at {$reportDir}");
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
        return implode("\n", $output);
    }

    private function cleanup(): void
    {
        if (is_dir($this->runPath)) {
            $this->exec('rm -rf ' . escapeshellarg($this->runPath));
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
