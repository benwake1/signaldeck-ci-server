<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Widgets;

use App\Enums\TriggerSource;
use App\Filament\Resources\TestRunResource\Pages\ViewTestRun;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;

class ProjectHealthWidget extends Widget
{
    protected static ?int $sort = 3;
    protected static string $view = 'filament.widgets.project-health';
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Project Health';

    public function triggerRun(int $projectId, int $suiteId): void
    {
        $suite = TestSuite::findOrFail($suiteId);

        // Verify the suite belongs to the given project — prevents cross-project
        // run triggering by an authenticated user who guesses numeric IDs.
        abort_if($suite->project_id !== $projectId, 403, 'Suite does not belong to this project.');

        $project = Project::findOrFail($projectId);
        $run = TestRun::create([
            'project_id'     => $projectId,
            'test_suite_id'  => $suiteId,
            'runner_type'    => $project->runner_type,
            'triggered_by'   => auth()->id(),
            'trigger_source' => TriggerSource::Manual,
            'storage_disk'   => config('filesystems.default'),
            'status'         => TestRun::STATUS_PENDING,
            'branch'         => $suite->effective_branch,
        ]);

        $run->dispatchJob();

        Notification::make()
            ->title('Test run queued!')
            ->body("Run #{$run->id} has been dispatched.")
            ->success()
            ->send();

        $this->redirect(ViewTestRun::getUrl(['record' => $run]));
    }

    public function getProjects()
    {
        return Project::with(['client', 'testRuns' => function ($q) {
            $q->whereIn('status', ['passing', 'failed'])->latest();
        }, 'testSuites'])
        ->where('active', true)
        ->get()
        ->map(function ($project) {
            $runs = $project->testRuns->take(10);
            $latest = $runs->first();
            $passRate = $runs->count() > 0
                ? round($runs->where('status', 'passing')->count() / $runs->count() * 100)
                : null;

            return [
                'id'          => $project->id,
                'name'        => $project->name,
                'client'      => $project->client->name,
                'latest'      => $latest,
                'pass_rate'   => $passRate,
                'suite_count' => $project->testSuites->count(),
                'suites'      => $project->testSuites->where('active', true),
            ];
        });
    }
}
