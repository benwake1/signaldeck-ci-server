<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TestRunResource\Pages\ViewTestRun;
use App\Jobs\RunCypressTestJob;
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

        $run = TestRun::create([
            'project_id'    => $projectId,
            'test_suite_id' => $suiteId,
            'triggered_by'  => auth()->id(),
            'status'        => TestRun::STATUS_PENDING,
            'branch'        => $suite->effective_branch,
        ]);

        RunCypressTestJob::dispatch($run);

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
            $q->whereIn('status', ['passing', 'failed'])->latest()->limit(10);
        }, 'testSuites'])
        ->where('active', true)
        ->get()
        ->map(function ($project) {
            $runs = $project->testRuns;
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
