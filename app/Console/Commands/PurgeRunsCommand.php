<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Project;
use App\Models\TestRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PurgeRunsCommand extends Command
{
    protected $signature = 'runs:purge
        {--days=           : Delete runs older than this many days}
        {--project=        : Restrict to a project ID}
        {--client=         : Restrict to a client ID}
        {--all             : Delete ALL runs (ignores --days)}
        {--purge-projects  : After deleting runs, force-delete projects that have no runs left}
        {--purge-clients   : After purging projects, force-delete clients that have no projects left}
        {--dry-run         : Preview what would be deleted without making changes}';

    protected $description = 'Delete test run records, results, and associated artifacts';

    public function handle(): int
    {
        $dryRun         = $this->option('dry-run');
        $all            = $this->option('all');
        $days           = $this->option('days');
        $projectFilter  = $this->option('project');
        $clientFilter   = $this->option('client');
        $purgeProjects  = $this->option('purge-projects');
        $purgeClients   = $this->option('purge-clients');

        if (!$all && $days === null) {
            $this->error('Provide --days=N, --all, or combine with --project/--client.');
            return self::FAILURE;
        }

        $query = TestRun::query();

        if (!$all && $days !== null) {
            $query->where('created_at', '<', now()->subDays((int) $days));
        }

        if ($projectFilter) {
            $query->where('project_id', $projectFilter);
        }

        if ($clientFilter) {
            $projectIds = Project::where('client_id', $clientFilter)->pluck('id');
            $query->whereIn('project_id', $projectIds);
        }

        $runCount = $query->count();

        if ($runCount === 0) {
            $this->info('No matching runs found.');
            return self::SUCCESS;
        }

        $this->warn(($dryRun ? '[DRY RUN] ' : '') . "Found {$runCount} run(s) to delete.");

        if (!$dryRun && !$this->confirm("Delete {$runCount} run(s) and all associated data?")) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        // Collect affected project IDs before deleting runs
        $affectedProjectIds = (clone $query)->distinct()->pluck('project_id');

        $deleted = 0;

        $query->chunkById(50, function ($runs) use ($dryRun, &$deleted) {
            foreach ($runs as $run) {
                $this->line("  Run #{$run->id} — project {$run->project_id} — {$run->created_at->format('d M Y')} [{$run->status}]");

                if (!$dryRun) {
                    $this->deleteArtifacts($run);
                    $run->testResults()->delete();
                    $run->delete();
                }

                $deleted++;
            }
        });

        $this->newLine();
        $action = $dryRun ? 'Would delete' : 'Deleted';
        $this->info("{$action} {$deleted} run(s).");

        if ($purgeProjects) {
            $this->purgeEmptyProjects($affectedProjectIds, $dryRun, $purgeClients);
        }

        return self::SUCCESS;
    }

    private function purgeEmptyProjects(\Illuminate\Support\Collection $projectIds, bool $dryRun, bool $purgeClients): void
    {
        $this->newLine();

        $emptyProjects = Project::withTrashed()
            ->whereIn('id', $projectIds)
            ->whereDoesntHave('testRuns')
            ->get();

        if ($emptyProjects->isEmpty()) {
            $this->info('No empty projects to remove.');
            return;
        }

        $this->warn(($dryRun ? '[DRY RUN] ' : '') . "Found {$emptyProjects->count()} empty project(s) to delete.");

        $affectedClientIds = $emptyProjects->pluck('client_id')->unique();

        foreach ($emptyProjects as $project) {
            $this->line("  Project #{$project->id} — {$project->name}");

            if (!$dryRun) {
                $project->testSuites()->delete();
                $project->forceDelete();
            }
        }

        $this->info(($dryRun ? 'Would delete' : 'Deleted') . " {$emptyProjects->count()} project(s).");

        if ($purgeClients) {
            $this->purgeEmptyClients($affectedClientIds, $dryRun);
        }
    }

    private function purgeEmptyClients(\Illuminate\Support\Collection $clientIds, bool $dryRun): void
    {
        $this->newLine();

        $emptyClients = Client::withTrashed()
            ->whereIn('id', $clientIds)
            ->whereDoesntHave('projects')
            ->get();

        if ($emptyClients->isEmpty()) {
            $this->info('No empty clients to remove.');
            return;
        }

        $this->warn(($dryRun ? '[DRY RUN] ' : '') . "Found {$emptyClients->count()} empty client(s) to delete.");

        foreach ($emptyClients as $client) {
            $this->line("  Client #{$client->id} — {$client->name}");

            if (!$dryRun) {
                $client->forceDelete();
            }
        }

        $this->info(($dryRun ? 'Would delete' : 'Deleted') . " {$emptyClients->count()} client(s).");
    }

    private function deleteArtifacts(TestRun $run): void
    {
        $disk      = $run->storage_disk ?? config('filesystems.default');
        $mediaDisk = match ($disk) {
            's3'    => 's3',
            'local' => 'local',
            default => 'public',
        };

        $reportDir   = "reports/run-{$run->id}";
        $artifactDir = "runs/{$run->id}";

        if (Storage::disk($disk)->exists($reportDir)) {
            Storage::disk($disk)->deleteDirectory($reportDir);
        }

        if (Storage::disk($mediaDisk)->exists($artifactDir)) {
            Storage::disk($mediaDisk)->deleteDirectory($artifactDir);
        }
    }
}
