<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Resources;

use App\Filament\Resources\TestRunResource\Pages;
use App\Models\TestRun;
use App\Models\TestSuite;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TestRunResource extends Resource
{
    protected static ?string $model = TestRun::class;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationGroup = 'Testing';
    protected static ?int $navigationSort = 1;
    protected static ?string $navigationLabel = 'Test Runs';

    // All authenticated users (admin + pm) may view test runs.
    public static function canViewAny(): bool { return auth()->check(); }
    public static function canCreate(): bool { return false; } // created via trigger action only
    public static function canEdit(\Illuminate\Database\Eloquent\Model $record): bool { return false; }
    public static function canDelete(\Illuminate\Database\Eloquent\Model $record): bool { return auth()->user()?->isAdmin() ?? false; }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('project_id')
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
                ->required()
                ->helperText('Override the branch for this run. Defaults to the suite/project branch.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('5s') // Auto-refresh for running tests
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('project.client.name')
                    ->label('Client')
                    ->searchable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('project.name')
                    ->label('Project')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('testSuite.name')
                    ->label('Suite')
                    ->searchable(),

                Tables\Columns\TextColumn::make('runner_type')
                    ->label('Runner')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof \App\Enums\RunnerType ? $state->label() : (\App\Enums\RunnerType::tryFrom($state)?->label() ?? $state))
                    ->color(fn ($state) => match ($state instanceof \App\Enums\RunnerType ? $state->value : $state) {
                        'playwright' => 'success',
                        default => 'info',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        'passing' => 'success',
                        'failed' => 'danger',
                        'running' => 'warning',
                        'cloning', 'installing' => 'info',
                        'error' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    })
                    ->icon(fn ($state) => match($state) {
                        'passing' => 'heroicon-o-check-circle',
                        'failed' => 'heroicon-o-x-circle',
                        'running' => 'heroicon-o-arrow-path',
                        'cloning' => 'heroicon-o-arrow-down-tray',
                        'installing' => 'heroicon-o-cog',
                        'error' => 'heroicon-o-exclamation-triangle',
                        default => 'heroicon-o-clock',
                    }),

                Tables\Columns\TextColumn::make('passed_tests')
                    ->label('✅ Passed')
                    ->color('success'),

                Tables\Columns\TextColumn::make('failed_tests')
                    ->label('❌ Failed')
                    ->color('danger'),

                Tables\Columns\TextColumn::make('total_tests')
                    ->label('Total'),

                Tables\Columns\TextColumn::make('branch')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('commit_sha')
                    ->label('Commit')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('triggeredBy.name')
                    ->label('By'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('project')
                    ->relationship('project', 'name'),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'running' => 'Running',
                        'passing' => 'Passing',
                        'failed' => 'Failed',
                        'error' => 'Error',
                        'cancelled' => 'Cancelled',
                    ]),

                Tables\Filters\SelectFilter::make('storage_disk')
                    ->label('Storage')
                    ->options([
                        's3'   => 'S3',
                        'local' => 'Local',
                    ])
                    ->query(fn ($query, $data) => match ($data['value'] ?? null) {
                        's3'    => $query->where('storage_disk', 's3'),
                        'local' => $query->where(fn ($q) => $q->whereNull('storage_disk')->orWhere('storage_disk', '!=', 's3')),
                        default => $query,
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('trigger_run')
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
                        $project = \App\Models\Project::find($data['project_id']);
                        $run = TestRun::create([
                            'project_id' => $data['project_id'],
                            'test_suite_id' => $data['test_suite_id'],
                            'runner_type' => $project->runner_type,
                            'triggered_by' => auth()->id(),
                            'status' => TestRun::STATUS_PENDING,
                            'branch' => $data['branch'],
                        ]);

                        $run->dispatchJob();

                        Notification::make()
                            ->title('Test run queued!')
                            ->body("Run #{$run->id} has been dispatched.")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('compare')
                    ->label('Compare 2 Runs')
                    ->icon('heroicon-o-arrows-right-left')
                    ->deselectRecordsAfterCompletion()
                    ->action(function ($records, \Livewire\Component $livewire) {
                        $ids = $records->pluck('id')->values();

                        if ($ids->count() !== 2) {
                            Notification::make()
                                ->title('Select exactly 2 runs to compare')
                                ->warning()
                                ->send();
                            return;
                        }

                        return redirect(\App\Filament\Pages\CompareRuns::getUrl([
                            'run_a' => $ids[0],
                            'run_b' => $ids[1],
                        ]));
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->url(fn (TestRun $record) => Pages\ViewTestRun::getUrl(['record' => $record])),

                Tables\Actions\Action::make('download_html')
                    ->label('HTML Report')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn (TestRun $record) => $record->report_html_url)
                    ->openUrlInNewTab()
                    ->visible(fn (TestRun $record) => $record->report_html_path !== null),

                Tables\Actions\Action::make('share_report')
                    ->label('Share Link')
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn (TestRun $record) => $record->report_html_path !== null)
                    ->modalHeading('Shareable Report Link')
                    ->modalDescription(fn (TestRun $record) => 'Send this link to anyone — no login required.')
                    ->modalContent(fn (TestRun $record) => view('filament.modals.share-link', [
                        'url' => $record->report_share_url,
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTestRuns::route('/'),
            'view' => Pages\ViewTestRun::route('/{record}/view'),
        ];
    }
}
