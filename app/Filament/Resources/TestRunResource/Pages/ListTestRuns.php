<?php

namespace App\Filament\Resources\TestRunResource\Pages;

use App\Filament\Resources\TestRunResource;
use App\Jobs\RunCypressTestJob;
use App\Models\TestRun;
use App\Models\TestSuite;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListTestRuns extends ListRecords
{
    protected static string $resource = TestRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('trigger_run')
                ->label('Run Tests')
                ->icon('heroicon-o-play')
                ->color('success')
                ->form([
                    Forms\Components\Select::make('project_id')
                        ->label('Project')
                        ->relationship('project', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(fn (Forms\Set $set) => $set('test_suite_id', null)),

                    Forms\Components\Select::make('test_suite_id')
                        ->label('Test Suite')
                        ->options(function (Forms\Get $get) {
                            $projectId = $get('project_id');
                            if (!$projectId) return [];
                            return TestSuite::where('project_id', $projectId)
                                ->where('active', true)
                                ->pluck('name', 'id');
                        })
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Forms\Get $get, Forms\Set $set) {
                            $suiteId = $get('test_suite_id');
                            if ($suiteId) {
                                $suite = TestSuite::with('project')->find($suiteId);
                                $set('branch', $suite?->effective_branch ?? 'main');
                            }
                        }),

                    Forms\Components\TextInput::make('branch')
                        ->label('Branch')
                        ->default('main')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $run = TestRun::create([
                        'project_id'    => $data['project_id'],
                        'test_suite_id' => $data['test_suite_id'],
                        'triggered_by'  => auth()->id(),
                        'status'        => TestRun::STATUS_PENDING,
                        'branch'        => $data['branch'],
                    ]);

                    RunCypressTestJob::dispatch($run);

                    Notification::make()
                        ->title('Test run queued!')
                        ->body("Run #{$run->id} has been dispatched to the queue.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
