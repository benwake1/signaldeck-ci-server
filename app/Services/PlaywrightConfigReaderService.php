<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

class PlaywrightConfigReaderService
{
    /**
     * Get the node bin directory from NPM_PATH config.
     * Returns null if not configured.
     */
    private function getNodeBinDir(): ?string
    {
        $npmPath = config('testing.npm_path') ?: env('NPM_PATH');

        if ($npmPath && file_exists($npmPath)) {
            return dirname($npmPath);
        }

        return null;
    }

    /**
     * Discover available Playwright projects (browser/device combos) from a repo's config.
     *
     * Clones the repo, runs `npx playwright test --list`, and extracts project names.
     * Results are cached on the Project model for subsequent form loads.
     *
     * @return string[] Array of project names, e.g. ["chromium", "firefox", "Mobile Safari"]
     */
    public function discoverProjects(Project $project): array
    {
        $instanceSlug = md5(base_path());
        $tmpDir = sys_get_temp_dir() . "/pw-discover-{$instanceSlug}/{$project->id}-" . time();

        try {
            mkdir($tmpDir, 0755, true);

            // Clone repo
            $sshKeyPath = null;
            $gitSshCommand = '';
            if ($project->deploy_key_private) {
                $sshKeyPath = tempnam(sys_get_temp_dir(), 'pw_ssh_');
                file_put_contents($sshKeyPath, $project->deploy_key_private);
                chmod($sshKeyPath, 0600);
                $gitSshCommand = 'GIT_SSH_COMMAND=' . escapeshellarg("ssh -i {$sshKeyPath} -o StrictHostKeyChecking=accept-new");
            }

            try {
                $branch = $project->default_branch;
                $cloneCmd = "{$gitSshCommand} git clone --depth 1 --branch " . escapeshellarg($branch)
                    . ' ' . escapeshellarg($project->repo_url)
                    . ' ' . escapeshellarg($tmpDir) . ' 2>&1';
                $this->exec($cloneCmd);
            } finally {
                if ($sshKeyPath && file_exists($sshKeyPath)) {
                    unlink($sshKeyPath);
                }
            }

            // Install dependencies
            $this->exec('cd ' . escapeshellarg($tmpDir) . ' && npm install --prefer-offline 2>&1');

            // Discover projects via --list flag with JSON reporter
            $projects = $this->discoverViaList($tmpDir);

            // Fallback: try parsing config directly
            if (empty($projects)) {
                $projects = $this->discoverViaConfigParse($tmpDir);
            }

            // Cache results on the project
            $project->update(['playwright_available_projects' => $projects]);

            return $projects;

        } catch (\Exception $e) {
            Log::warning('Playwright project discovery failed', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            if (is_dir($tmpDir)) {
                exec('rm -rf ' . escapeshellarg($tmpDir));
            }
        }
    }

    /**
     * Discover projects using `npx playwright test --list`.
     * The output includes project names in the test IDs.
     */
    private function discoverViaList(string $dir): array
    {
        try {
            $result = $this->exec('cd ' . escapeshellarg($dir) . ' && npx playwright test --list 2>&1');
        } catch (\RuntimeException $e) {
            // --list may exit non-zero if no tests found, parse output anyway
            $result = $e->getMessage();
        }

        if (empty($result)) {
            return [];
        }

        // The --list output shows lines like:
        //   [chromium] > tests/example.spec.ts:3:5 > has title
        //   [firefox] > tests/example.spec.ts:3:5 > has title
        $projects = [];
        foreach (explode("\n", $result) as $line) {
            if (preg_match('/^\s*\[([^\]]+)\]/', $line, $matches)) {
                $projects[$matches[1]] = true;
            }
        }

        return array_keys($projects);
    }

    /**
     * Fallback: try to extract project names by evaluating the config.
     */
    private function discoverViaConfigParse(string $dir): array
    {
        // Try to read projects from the config using Node
        $script = <<<'JS'
            try {
                const path = require('path');
                const configPath = ['playwright.config.ts', 'playwright.config.js', 'playwright.config.mjs']
                    .map(f => path.join(process.cwd(), f))
                    .find(f => require('fs').existsSync(f));
                if (!configPath) { console.log('[]'); process.exit(0); }

                // Use tsx or ts-node for TypeScript configs
                delete require.cache[configPath];
                let config;
                try {
                    config = require(configPath);
                } catch (e) {
                    // TypeScript config — try dynamic import
                    import(configPath).then(m => {
                        const c = m.default || m;
                        const projects = (c.projects || []).map(p => p.name).filter(Boolean);
                        console.log(JSON.stringify(projects));
                    }).catch(() => console.log('[]'));
                    return;
                }
                config = config.default || config;
                const projects = (config.projects || []).map(p => p.name).filter(Boolean);
                console.log(JSON.stringify(projects));
            } catch (e) { console.log('[]'); }
        JS;

        $result = '';
        try {
            $result = $this->exec('cd ' . escapeshellarg($dir) . ' && node -e ' . escapeshellarg($script) . ' 2>/dev/null');
        } catch (\RuntimeException $e) {
            // Ignore failures — this is a fallback
        }

        $json = trim($result);
        if ($json) {
            $parsed = json_decode($json, true);
            if (is_array($parsed)) {
                return $parsed;
            }
        }

        return [];
    }

    private function exec(string $command): string
    {
        $output = [];
        $returnCode = 0;

        $binDir = $this->getNodeBinDir();
        if ($binDir) {
            $command = 'export PATH=' . escapeshellarg($binDir) . ':$PATH && ' . $command;
        }

        exec('/bin/bash -c ' . escapeshellarg($command), $output, $returnCode);
        $result = implode("\n", $output);

        if ($returnCode !== 0) {
            throw new \RuntimeException("Command failed (exit {$returnCode}): {$result}");
        }

        return $result;
    }
}
