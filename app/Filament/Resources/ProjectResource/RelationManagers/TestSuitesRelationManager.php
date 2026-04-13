<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Resources\ProjectResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TestSuitesRelationManager extends RelationManager
{
    protected static string $relationship = 'testSuites';
    protected static ?string $title = 'Test Suites';

    public function form(Form $form): Form
    {
        $project = $this->getOwnerRecord();
        $isPlaywright = $project->isPlaywright();
        $defaultSpec = $isPlaywright
            ? config('testing.playwright.default_spec_pattern')
            : config('testing.cypress.default_spec_pattern');

        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('spec_pattern')
                ->label('Spec Pattern')
                ->placeholder($defaultSpec)
                ->required()
                ->default($defaultSpec),

            Forms\Components\TextInput::make('branch_override')
                ->label('Branch Override')
                ->placeholder('Leave blank to use project default'),

            Forms\Components\TextInput::make('timeout_minutes')
                ->label('Timeout (minutes)')
                ->numeric()
                ->default(30),

            Forms\Components\Textarea::make('description')
                ->rows(2)
                ->columnSpanFull(),

            ...($isPlaywright && !empty($project->playwright_available_projects)
                ? [
                    Forms\Components\CheckboxList::make('playwright_projects')
                        ->label('Playwright Projects (Browsers / Devices)')
                        ->options(array_combine(
                            $project->playwright_available_projects,
                            $project->playwright_available_projects,
                        ))
                        ->columns(3)
                        ->columnSpanFull()
                        ->helperText('Select which browsers/devices to run. Leave all unchecked to run all projects.'),
                ]
                : ($isPlaywright
                    ? [
                        Forms\Components\TagsInput::make('playwright_projects')
                            ->label('Playwright Projects (Browsers / Devices)')
                            ->placeholder('e.g. chromium, firefox, webkit, Mobile Safari')
                            ->columnSpanFull()
                            ->helperText('Type browser/device names, or use "Discover Projects" on the project edit page to auto-detect from playwright.config.ts.'),
                    ]
                    : []
                )
            ),

            ...($isPlaywright && auth()->user()?->isAdmin()
                ? [
                    Forms\Components\Section::make('Performance Tuning')
                        ->description('These settings override the project\'s playwright.config.ts values via CLI flags.')
                        ->icon('heroicon-o-adjustments-horizontal')
                        ->collapsed()
                        ->columns(2)
                        ->schema([
                            Forms\Components\TextInput::make('playwright_workers')
                                ->label('Parallel Workers')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(16)
                                ->placeholder('Config default')
                                ->helperText('Number of parallel worker processes. Higher = faster but more resource intensive.'),

                            Forms\Components\TextInput::make('playwright_retries')
                                ->label('Retries')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(5)
                                ->placeholder('Config default')
                                ->helperText('Number of retries for failed tests. 0 = no retries.'),
                        ]),
                ]
                : []
            ),

            Forms\Components\KeyValue::make('env_variables')
                ->label('Environment Variable Overrides')
                ->keyLabel('Variable')
                ->valueLabel('Value')
                ->columnSpanFull(),

            Forms\Components\Toggle::make('active')->default(true),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('name')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('spec_pattern')->limit(40)->copyable(),
                Tables\Columns\TextColumn::make('branch_override')->placeholder('project default')->badge()->color('warning'),
                Tables\Columns\TextColumn::make('timeout_minutes')->suffix('m')->label('Timeout'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
