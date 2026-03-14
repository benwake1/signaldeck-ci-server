<?php

namespace App\Filament\Resources\TestRunResource\Pages;

use App\Filament\Resources\TestRunResource;
use App\Models\TestRun;
use App\Services\ReportGeneratorService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function pollStatus(): void
    {
        $this->record = $this->record->fresh();
        $this->dispatch('run-status-updated', status: $this->record->status);
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
                ->modalDescription('The running Cypress process will be terminated. This cannot be undone.')
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
                        'triggered_by'  => auth()->id(),
                        'status'        => TestRun::STATUS_PENDING,
                        'branch'        => $this->record->branch,
                    ]);

                    \App\Jobs\RunCypressTestJob::dispatch($newRun);

                    \Filament\Notifications\Notification::make()
                        ->title('Re-run queued!')
                        ->body("New run #{$newRun->id} dispatched.")
                        ->success()
                        ->send();

                    $this->redirect(ViewTestRun::getUrl(['record' => $newRun]));
                }),
        ];
    }
}
