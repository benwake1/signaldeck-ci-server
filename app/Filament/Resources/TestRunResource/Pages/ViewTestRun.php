<?php

namespace App\Filament\Resources\TestRunResource\Pages;

use App\Filament\Resources\TestRunResource;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Services\ReportGeneratorService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewTestRun extends ViewRecord
{
    protected static string $resource = TestRunResource::class;
    protected static string $view = 'filament.test-run.view';

    public function getTitle(): string
    {
        return "Test Run #{$this->record->id} — {$this->record->project->name}";
    }

    public function getSubheading(): ?string
    {
        return "Suite: {$this->record->testSuite->name} · Branch: {$this->record->branch}";
    }

    protected function getViewData(): array
    {
        // Compute flaky test titles for this project so the blade can badge them
        $failedTitles = $this->record->testResults
            ->where('status', 'failed')
            ->pluck('full_title')
            ->unique()
            ->values()
            ->toArray();

        $flakyTestTitles = [];

        if (!empty($failedTitles)) {
            $flakyTestTitles = DB::table('test_results as tr')
                ->join('test_runs', 'test_runs.id', '=', 'tr.test_run_id')
                ->where('test_runs.project_id', $this->record->project_id)
                ->whereIn('tr.full_title', $failedTitles)
                ->whereIn('tr.status', ['passed', 'failed'])
                ->groupBy('tr.full_title')
                ->havingRaw('COUNT(*) >= 3')
                ->havingRaw('SUM(CASE WHEN tr.status = \'passed\' THEN 1 ELSE 0 END) > 0')
                ->havingRaw('SUM(CASE WHEN tr.status = \'failed\' THEN 1 ELSE 0 END) > 0')
                ->pluck('tr.full_title')
                ->toArray();
        }

        return ['flakyTestTitles' => $flakyTestTitles];
    }

    public function pollStatus(): void
    {
        $this->record = $this->record->fresh();
        $this->dispatch('run-status-updated', status: $this->record->status);
        $this->dispatch('log-updated', log: $this->record->log_output ?? '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cancel_run')
                ->label('Cancel Run')
                ->icon('heroicon-o-stop-circle')
                ->color('danger')
                ->visible(fn () => $this->record->isRunning())
                ->requiresConfirmation()
                ->modalHeading('Cancel this test run?')
                ->modalDescription('The running test process will be terminated. This cannot be undone.')
                ->action(function () {
                    $this->record->update([
                        'status'        => TestRun::STATUS_CANCELLED,
                        'finished_at'   => now(),
                        'error_message' => 'Cancelled by ' . auth()->user()->name,
                    ]);

                    $this->record = $this->record->fresh();

                    broadcast(new \App\Events\TestRunStatusChanged($this->record));

                    \Filament\Notifications\Notification::make()
                        ->title('Run cancelled')
                        ->warning()
                        ->send();

                    $this->redirect(ViewTestRun::getUrl(['record' => $this->record]));
                }),

            Actions\Action::make('share_report')
                ->label('Share Link')
                ->icon('heroicon-o-link')
                ->color('gray')
                ->visible(fn () => $this->record->report_html_path !== null)
                ->modalHeading('Shareable Report Link')
                ->modalDescription('Send this link to anyone — no login required.')
                ->modalContent(fn () => view('filament.modals.share-link', [
                    'url' => $this->record->report_share_url,
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            Actions\Action::make('download_html')
                ->label('HTML Report')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->url(fn () => $this->record->report_html_url)
                ->openUrlInNewTab()
                ->visible(fn () => $this->record->report_html_path !== null),

            Actions\Action::make('regenerate_report')
                ->label('Regenerate Report')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => $this->record->isComplete())
                ->action(function (ReportGeneratorService $reporter) {
                    $reporter->generateHtmlReport($this->record->fresh());

                    \Filament\Notifications\Notification::make()
                        ->title('Report regenerated!')
                        ->success()
                        ->send();
                }),

            Actions\Action::make('re_run')
                ->label('Re-run')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => $this->record->isComplete())
                ->action(function () {
                    $newRun = TestRun::create([
                        'project_id'    => $this->record->project_id,
                        'test_suite_id' => $this->record->test_suite_id,
                        'runner_type'   => $this->record->runner_type,
                        'triggered_by'  => auth()->id(),
                        'status'        => TestRun::STATUS_PENDING,
                        'branch'        => $this->record->branch,
                        'parent_run_id' => $this->record->id,
                    ]);

                    $newRun->dispatchJob();

                    \Filament\Notifications\Notification::make()
                        ->title('Re-run queued!')
                        ->body("New run #{$newRun->id} dispatched.")
                        ->success()
                        ->send();

                    $this->redirect(ViewTestRun::getUrl(['record' => $newRun]));
                }),

            Actions\Action::make('re_run_failures')
                ->label('Re-run Failures')
                ->icon('heroicon-o-bug-ant')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Re-run failed tests only?')
                ->modalDescription('A new run will be created targeting only the spec files that had failures. Passing specs will be skipped.')
                ->visible(fn () => $this->record->isComplete() && $this->record->failed_tests > 0)
                ->action(function () {
                    $failingSpecs = $this->record->testResults
                        ->where('status', 'failed')
                        ->pluck('spec_file')
                        ->unique()
                        ->values()
                        ->join(',');

                    $newRun = TestRun::create([
                        'project_id'    => $this->record->project_id,
                        'test_suite_id' => $this->record->test_suite_id,
                        'runner_type'   => $this->record->runner_type,
                        'triggered_by'  => auth()->id(),
                        'status'        => TestRun::STATUS_PENDING,
                        'branch'        => $this->record->branch,
                        'spec_override' => $failingSpecs,
                        'parent_run_id' => $this->record->id,
                    ]);

                    $newRun->dispatchJob();

                    \Filament\Notifications\Notification::make()
                        ->title('Re-run failures queued!')
                        ->body("Run #{$newRun->id} will only execute the failing specs.")
                        ->success()
                        ->send();

                    $this->redirect(ViewTestRun::getUrl(['record' => $newRun]));
                }),

            Actions\Action::make('compare')
                ->label('Compare')
                ->icon('heroicon-o-arrows-right-left')
                ->color('gray')
                ->visible(fn () => $this->record->isComplete())
                ->form([
                    \Filament\Forms\Components\Select::make('compare_run_id')
                        ->label('Compare with run')
                        ->options(fn () => TestRun::where('project_id', $this->record->project_id)
                            ->where('id', '!=', $this->record->id)
                            ->whereIn('status', ['passing', 'failed'])
                            ->orderByDesc('created_at')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn ($r) => [$r->id => "#{$r->id} · {$r->branch} · {$r->status} · {$r->created_at->diffForHumans()}"])
                        )
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data) {
                    $this->redirect(
                        \App\Filament\Pages\CompareRuns::getUrl([
                            'run_a' => $this->record->id,
                            'run_b' => $data['compare_run_id'],
                        ])
                    );
                }),
        ];
    }
}
