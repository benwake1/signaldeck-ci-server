<?php

namespace App\Filament\Resources;

use App\Enums\RunnerType;
use App\Filament\Resources\ProjectResource\Pages;
use App\Filament\Resources\ProjectResource\RelationManagers;
use App\Models\Project;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ProjectResource extends Resource
{
    protected static ?string $model = Project::class;
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationGroup = 'Management';

    public static function canViewAny(): bool { return auth()->user()?->isAdmin() ?? false; }
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Project Details')
                ->schema([
                    Forms\Components\Select::make('client_id')
                        ->relationship('client', 'name')
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm([
                            Forms\Components\TextInput::make('name')->required(),
                            Forms\Components\TextInput::make('contact_email')->email(),
                        ]),

                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn ($state, Forms\Set $set) =>
                            $set('slug', \Illuminate\Support\Str::slug($state))
                        ),

                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Forms\Components\Textarea::make('description')
                        ->rows(2)
                        ->columnSpanFull(),

                    Forms\Components\Select::make('runner_type')
                        ->label('Test Runner')
                        ->options([
                            'cypress' => 'Cypress',
                            'playwright' => 'Playwright',
                        ])
                        ->default('cypress')
                        ->required()
                        ->native(false)
                        ->live(),
                ])->columns(2),

            Forms\Components\Section::make('Repository')
                ->schema([
                    Forms\Components\Select::make('repo_provider')
                        ->options([
                            'github' => 'GitHub',
                            'bitbucket' => 'Bitbucket',
                            'gitlab' => 'GitLab',
                            'other' => 'Other (HTTPS)',
                        ])
                        ->default('github')
                        ->required(),

                    Forms\Components\TextInput::make('default_branch')
                        ->default('main')
                        ->required(),

                    Forms\Components\TextInput::make('repo_url')
                        ->label('Repository URL')
                        ->placeholder('git@github.com:org/repo.git or https://github.com/org/repo.git')
                        ->required()
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('deploy_key_public')
                        ->label('SSH Public Key (add this to your repo)')
                        ->rows(3)
                        ->extraAttributes(['style' => 'font-family: monospace; font-size: 0.75rem;'])
                        ->columnSpanFull()
                        ->helperText('Copy this public key and add it as a Deploy Key in your repository settings (read-only access is sufficient).'),
                ])->columns(2),

            Forms\Components\Section::make('Environment Variables')
                ->description('These variables will be available to all test suites in this project. Values are encrypted at rest.')
                ->schema([
                    Forms\Components\KeyValue::make('env_variables')
                        ->label('')
                        ->keyLabel('Variable Name')
                        ->valueLabel('Value')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Playwright Configuration')
                ->description('Discover available browsers and devices from your playwright.config.ts. Results are cached for use in test suite configuration.')
                ->schema([
                    Forms\Components\Placeholder::make('playwright_available_projects_display')
                        ->label('Discovered Projects')
                        ->content(function ($record) {
                            $projects = $record?->playwright_available_projects ?? [];
                            return empty($projects)
                                ? 'No projects discovered yet. Click "Discover Projects" below.'
                                : implode(', ', $projects);
                        }),

                    Forms\Components\Actions::make([
                        Forms\Components\Actions\Action::make('discover_playwright_projects')
                            ->label('Discover Projects')
                            ->icon('heroicon-o-magnifying-glass')
                            ->color('warning')
                            ->requiresConfirmation()
                            ->modalHeading('Discover Playwright Projects')
                            ->modalDescription('This will clone the repository and read the playwright.config.ts to discover available browser/device projects. This may take a minute.')
                            ->action(function ($record, $livewire) {
                                try {
                                    $service = app(\App\Services\PlaywrightConfigReaderService::class);
                                    $projects = $service->discoverProjects($record);

                                    Notification::make()
                                        ->title('Projects discovered!')
                                        ->body('Found: ' . implode(', ', $projects))
                                        ->success()
                                        ->send();

                                    $livewire->refreshFormData(['playwright_available_projects']);
                                } catch (\Exception $e) {
                                    Notification::make()
                                        ->title('Discovery failed')
                                        ->body($e->getMessage())
                                        ->danger()
                                        ->send();
                                }
                            }),
                    ]),
                ])
                ->visible(fn ($get) => $get('runner_type') === 'playwright')
                ->collapsible(),

            Forms\Components\Toggle::make('active')
                ->label('Active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('runner_type')
                    ->label('Runner')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof RunnerType ? $state->label() : (RunnerType::tryFrom($state)?->label() ?? $state))
                    ->color(fn ($state) => match ($state instanceof RunnerType ? $state->value : $state) {
                        'playwright' => 'success',
                        default => 'info',
                    }),

                Tables\Columns\TextColumn::make('repo_url')
                    ->label('Repository')
                    ->limit(40)
                    ->copyable(),

                Tables\Columns\TextColumn::make('default_branch')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('testRuns_count')
                    ->label('Total Runs')
                    ->counts('testRuns')
                    ->badge(),

                Tables\Columns\TextColumn::make('latest_run.status')
                    ->label('Last Run')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        'passing' => 'success',
                        'failed' => 'danger',
                        'running', 'cloning', 'installing' => 'warning',
                        'error' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('client')
                    ->relationship('client', 'name'),
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->actions([
                Tables\Actions\Action::make('generate_key')
                    ->label('Generate Key')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('This will generate a new SSH deploy key pair for this project. The existing key (if any) will be replaced.')
                    ->action(function (Project $record) {
                        $keys = $record->generateDeployKey();
                        Notification::make()
                            ->title('Deploy key generated!')
                            ->body('Public key: ' . $keys['public'])
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\TestSuitesRelationManager::class,
            RelationManagers\TestRunsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProjects::route('/'),
            'create' => Pages\CreateProject::route('/create'),
            'edit' => Pages\EditProject::route('/{record}/edit'),
        ];
    }
}
