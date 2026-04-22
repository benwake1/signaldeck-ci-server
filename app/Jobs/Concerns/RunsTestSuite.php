<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Jobs\Concerns;

use App\Models\TestRun;
use Illuminate\Support\Facades\Log;

trait RunsTestSuite
{
    private string $runPath;
    private ?int $childPid = null;

    protected function initRunPath(): void
    {
        $instanceSlug = md5(base_path());
        $this->runPath = sys_get_temp_dir() . "/test-runs-{$instanceSlug}/{$this->run->id}";
    }

    protected function cloneRepo(): void
    {
        $project = $this->run->project;

        if (is_dir($this->runPath)) {
            $this->exec('rm -rf ' . escapeshellarg($this->runPath));
        }
        mkdir($this->runPath, 0755, true);

        $branch  = $this->run->branch;
        $repoUrl = $project->repo_url;

        $sshKeyPath = null;
        if ($project->deploy_key_private) {
            $sshKeyPath    = $this->setupSshKey($project->deploy_key_private);
            $gitSshCommand = 'GIT_SSH_COMMAND=' . escapeshellarg("ssh -i {$sshKeyPath} -o StrictHostKeyChecking=accept-new");
        } else {
            $gitSshCommand = '';
        }

        $this->log("🔄 Cloning {$repoUrl} (branch: {$branch})...");

        try {
            $cloneCmd = "{$gitSshCommand} git clone --depth 1 --branch " . escapeshellarg($branch) . ' ' . escapeshellarg($repoUrl) . ' ' . escapeshellarg($this->runPath) . ' 2>&1';
            $this->exec($cloneCmd);

            $sha = trim($this->exec('git -C ' . escapeshellarg($this->runPath) . ' rev-parse HEAD 2>&1'));
            if (strlen($sha) === 40) {
                $this->run->update(['commit_sha' => substr($sha, 0, 8)]);
            }
        } finally {
            if ($sshKeyPath && file_exists($sshKeyPath)) {
                unlink($sshKeyPath);
            }
        }

        $this->log("✅ Repository cloned successfully.");
    }

    protected function setupSshKey(string $privateKey): string
    {
        $keyPath = tempnam(sys_get_temp_dir(), 'test_ssh_');
        file_put_contents($keyPath, $privateKey);
        chmod($keyPath, 0600);
        return $keyPath;
    }

    protected function installDependencies(): void
    {
        $this->log("📦 Installing npm dependencies...");
        $this->exec('cd ' . escapeshellarg($this->runPath) . ' && npm install --prefer-offline 2>&1');
        $this->log("✅ Dependencies installed.");
    }

    protected function buildTailwind(): void
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

    protected function buildEnvString(): string
    {
        $suite = $this->run->testSuite;
        $project = $this->run->project;

        $envVars = array_merge(
            $project->env_variables,
            $suite->env_variables
        );

        $envString = '';
        foreach ($envVars as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }
            $envString .= "{$key}=" . escapeshellarg($value) . ' ';
        }

        return $envString;
    }

    protected function streamProcess(string $cmd): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start test process');
        }

        fclose($pipes[0]);

        $initialStatus  = proc_get_status($process);
        $this->childPid = $initialStatus['pid'] ?: null;

        // Capture the child's process group ID now while the process is alive.
        // proc_open children inherit PHP's PGID by default, but launchers like
        // xvfb-run and npx typically call setsid/setpgrp, putting themselves in
        // a new group. We only group-kill when the child's PGID differs from
        // PHP's — otherwise posix_kill(-pgid) would take out the worker itself.
        $childPgid = ($this->childPid && function_exists('posix_getpgid'))
            ? (@posix_getpgid($this->childPid) ?: null)
            : null;
        $phpPgid = function_exists('posix_getpgrp') ? posix_getpgrp() : null;

        // Preserve pipeline log messages (clone, install, etc.) that were already persisted
        $fullLog  = $this->run->log_output ?? '';
        $lastFlush = time();
        $dirty    = false;
        $exitCode = -1;
        stream_set_blocking($pipes[1], false);

        try {
            while (true) {
                if ($this->run->fresh()->status === TestRun::STATUS_CANCELLED) {
                    throw new \RuntimeException('Run cancelled by user.');
                }

                $read = [$pipes[1]];
                $write = null;
                $except = null;

                if (stream_select($read, $write, $except, 1) > 0) {
                    $line = fgets($pipes[1]);
                    if ($line !== false) {
                        $clean = trim($this->stripAnsi($line));
                        if ($clean !== '') {
                            $fullLog .= $clean . "\n";
                            $dirty = true;
                            $this->log($clean, persist: false);
                        }
                    }
                }

                // Flush log to DB every 3 seconds so polling can pick it up
                if ($dirty && time() - $lastFlush >= 3) {
                    $this->run->update(['log_output' => $fullLog]);
                    $lastFlush = time();
                    $dirty = false;
                }

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $remaining = stream_get_contents($pipes[1]);
                    if ($remaining) {
                        foreach (explode("\n", $remaining) as $line) {
                            $clean = $this->stripAnsi(trim($line));
                            if ($clean !== '') {
                                $fullLog .= $clean . "\n";
                                $this->log($clean, persist: false);
                            }
                        }
                    }
                    break;
                }
            }
        } finally {
            // Kill the child process group so browser workers / npx grandchildren
            // don't outlive the job when the PHP worker exits unexpectedly.
            // Fall back to killing just the direct child when group kill isn't safe.
            if ($childPgid && $phpPgid && $childPgid !== $phpPgid) {
                @posix_kill(-$childPgid, SIGKILL);
            } elseif ($this->childPid) {
                @posix_kill($this->childPid, SIGKILL);
            }
            $this->childPid = null;

            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, SIGKILL);
                }
                if (is_resource($pipes[1])) {
                    fclose($pipes[1]);
                }
                $closed = proc_close($process);
                // proc_close returns -1 when killed by signal; preserve the
                // real exit code from the normal (clean exit) path.
                if ($closed >= 0) {
                    $exitCode = $closed;
                }
            }

            // Always flush whatever log we have — covers both normal and exception paths.
            $this->run->update(['log_output' => $fullLog]);
        }

        return $exitCode;
    }

    protected function stripAnsi(string $text): string
    {
        return preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $text);
    }

    protected function checkCancelled(): void
    {
        if ($this->run->fresh()->status === TestRun::STATUS_CANCELLED) {
            throw new \RuntimeException('Run cancelled by user.');
        }
    }

    protected function updateStatus(string $status): void
    {
        // Never overwrite a user-initiated cancel — use a conditional UPDATE so the
        // write is a no-op if the run was cancelled between phases.
        TestRun::where('id', $this->run->id)
            ->where('status', '!=', TestRun::STATUS_CANCELLED)
            ->update(['status' => $status]);

        $this->run->refresh();

        if ($this->run->status === TestRun::STATUS_CANCELLED) {
            throw new \RuntimeException('Run cancelled by user.');
        }
    }

    protected function log(string $message, bool $persist = true): void
    {
        $timestamp = now()->format('H:i:s');
        $logLine = "[{$timestamp}] {$message}";

        Log::info("Run #{$this->run->id}: {$message}");

        // Persist to DB so polling can pick up pipeline messages (clone, install, etc.)
        if ($persist) {
            $current = $this->run->log_output ?? '';
            $this->run->update(['log_output' => $current . $logLine . "\n"]);
        }
    }

    protected function exec(string $command): string
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

    protected function cleanup(): void
    {
        if (is_dir($this->runPath)) {
            exec('rm -rf ' . escapeshellarg($this->runPath));
        }
    }
}
