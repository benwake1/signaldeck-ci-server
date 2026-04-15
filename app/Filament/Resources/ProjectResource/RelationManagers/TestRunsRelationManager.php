<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use App\Models\TestRun;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Form;

class TestRunsRelationManager extends RelationManager
{
    protected static string $relationship = 'testRuns';
    protected static ?string $title = 'Test Run History';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('testSuite.name')->label('Suite'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        'passing' => 'success',
                        'failed' => 'danger',
                        'running', 'cloning', 'installing' => 'warning',
                        'error' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('passed_tests')->label('Passed')->color('success'),
                Tables\Columns\TextColumn::make('failed_tests')->label('Failed')->color('danger'),
                Tables\Columns\TextColumn::make('total_tests')->label('Total'),
                Tables\Columns\TextColumn::make('branch')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('triggeredBy.name')
                    ->label('Triggered By')
                    ->default(fn (TestRun $record) => $record->trigger_source?->label() ?? '—'),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->label('Started'),
            ])
            ->actions([
                Tables\Actions\Action::make('view')
                    ->url(fn ($record) => route('filament.admin.resources.test-runs.view', $record))
                    ->icon('heroicon-o-eye'),
            ]);
    }
}
