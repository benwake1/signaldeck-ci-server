<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\TestRunResource\Pages\ViewTestRun;
use App\Jobs\RunCypressTestJob;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\TestSuite;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentRunsWidget extends BaseWidget
{
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';
    protected static ?string $heading = 'Recent Test Runs';

    public function table(Table $table): Table
    {
        return $table
            ->query(TestRun::with(['project.client', 'testSuite', 'triggeredBy'])->latest()->limit(20))
            ->poll('5s')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->width(50),

                Tables\Columns\TextColumn::make('project.client.name')
                    ->label('Client')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('project.name')
                    ->label('Project')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('testSuite.name')
                    ->label('Suite'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'passing'              => 'success',
                        'failed', 'error'      => 'danger',
                        'running'              => 'warning',
                        'cloning','installing' => 'info',
                        default                => 'gray',
                    })
                    ->icon(fn ($state) => match ($state) {
                        'passing'   => 'heroicon-o-check-circle',
                        'failed'    => 'heroicon-o-x-circle',
                        'error'     => 'heroicon-o-exclamation-triangle',
                        'running'   => 'heroicon-o-arrow-path',
                        'cloning'   => 'heroicon-o-arrow-down-tray',
                        'installing'=> 'heroicon-o-cog',
                        default     => 'heroicon-o-clock',
                    }),

                Tables\Columns\TextColumn::make('passed_tests')->label('✅')->color('success'),
                Tables\Columns\TextColumn::make('failed_tests')->label('❌')->color('danger'),
                Tables\Columns\TextColumn::make('total_tests')->label('Total'),

                Tables\Columns\TextColumn::make('branch')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('triggeredBy.name')->label('By'),
                Tables\Columns\TextColumn::make('created_at')->since()->label('When'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->icon('heroicon-o-eye')
                    ->url(fn (TestRun $record) => ViewTestRun::getUrl(['record' => $record])),

                Tables\Actions\Action::make('html_report')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn (TestRun $record) => $record->report_html_url)
                    ->openUrlInNewTab()
                    ->visible(fn (TestRun $record) => $record->report_html_path !== null),

            ]);
    }
}
