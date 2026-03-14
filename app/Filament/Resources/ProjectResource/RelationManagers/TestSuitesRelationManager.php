<?php

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
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->required()
                ->maxLength(255),

            Forms\Components\TextInput::make('spec_pattern')
                ->label('Spec Pattern')
                ->placeholder('cypress/e2e/**/*.cy.{js,jsx,ts,tsx}')
                ->required()
                ->default('cypress/e2e/**/*.cy.{js,jsx,ts,tsx}'),

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
