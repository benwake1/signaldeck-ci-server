<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

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

                    Forms\Components\Placeholder::make('deploy_key_status')
                        ->label('SSH Deploy Key')
                        ->content(fn ($record) => $record?->getRawOriginal('deploy_key_public')
                            ? '🔑 A deploy key is configured. Use "Generate Key" from the project list to replace it — the new key will be shown once in a notification.'
                            : 'No deploy key generated. Use "Generate Key" from the project list.')
                        ->columnSpanFull(),
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
                            ->disabled(fn ($record) => $record === null)
                            ->tooltip(fn ($record) => $record === null ? 'Save the project first before discovering projects' : null)
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
                ->visible(fn ($get) => $get('runner_type') === 'playwright' || $get('runner_type') === RunnerType::Playwright)
                ->collapsible(),

            Forms\Components\Section::make('Webhook')
                ->description('Trigger test runs from CI pipelines, GitHub Actions, or any HTTP client.')
                ->schema([
                    Forms\Components\Placeholder::make('webhook_secret_status')
                        ->label('Secret status')
                        ->content(fn ($record) => $record?->getRawOriginal('webhook_secret')
                            ? '✓ Secret configured — use "Rotate Webhook Secret" from the project list to regenerate.'
                            : '⚠ No secret set — use "Rotate Webhook Secret" from the project list to generate one.'),

                    Forms\Components\Placeholder::make('webhook_usage')
                        ->label('Usage')
                        ->columnSpanFull()
                        ->content(function ($record) {
                            if (! $record) {
                                return 'Save the project first.';
                            }

                            $url            = route('api.v1.webhook.trigger');
                            $suites         = $record->testSuites()->select('id', 'name')->get();
                            $secret         = $record->getRawOriginal('webhook_secret') ? '<your_secret>' : '(generate a secret first)';
                            $exampleSuiteId = $suites->first()?->id ?? 1;

                            $suiteList = $suites->isEmpty()
                                ? '    (no suites yet — add one in the Test Suites tab below)'
                                : $suites->map(fn ($s) => "    {$s->id}  {$s->name}")->join("\n");

                            $payloadJson = json_encode(['suite_id' => $exampleSuiteId, 'branch' => 'main'], JSON_PRETTY_PRINT);

                            $curl = <<<BASH
# jq safely encodes any branch name, including slashes and quotes
PAYLOAD=\$(jq -cn --argjson suite_id {$exampleSuiteId} --arg branch "main" \\
  '{suite_id: \$suite_id, branch: \$branch}')
SIG=\$(echo -n "\$PAYLOAD" | openssl dgst -sha256 -hmac "{$secret}" | awk '{print \$2}')
curl -sf -X POST "{$url}" \\
  -H "Content-Type: application/json" \\
  -H "X-Webhook-Signature: \$SIG" \\
  -d "\$PAYLOAD"
BASH;

                            $githubActions = <<<YAML
# .github/workflows/trigger-tests.yml
#
# Runs automatically on push to main, or manually via Actions UI / API
# with a configurable suite_id and branch.
name: Trigger Test Run
on:
  push:
    branches: [main]
  workflow_dispatch:
    inputs:
      suite_id:
        description: 'Test suite ID (see project Webhook tab for IDs)'
        required: true
        default: '{$exampleSuiteId}'
      branch:
        description: 'Branch to test (leave blank to use the triggering branch)'
        required: false

jobs:
  trigger:
    runs-on: ubuntu-latest
    steps:
      - name: Trigger test run
        env:
          WEBHOOK_SECRET: \${{ secrets.TEST_DASHBOARD_WEBHOOK_SECRET }}
          SUITE_ID: \${{ inputs.suite_id || '{$exampleSuiteId}' }}
          BRANCH: \${{ inputs.branch || github.ref_name }}
        run: |
          PAYLOAD=\$(jq -cn --argjson suite_id "\$SUITE_ID" --arg branch "\$BRANCH" \\
            '{suite_id: \$suite_id, branch: \$branch}')
          SIG=\$(echo -n "\$PAYLOAD" | openssl dgst -sha256 -hmac "\$WEBHOOK_SECRET" | awk '{print \$2}')
          curl -sf -X POST "{$url}" \\
            -H "Content-Type: application/json" \\
            -H "X-Webhook-Signature: \$SIG" \\
            -d "\$PAYLOAD"

# Repository secret required (Settings → Secrets → Actions):
#   TEST_DASHBOARD_WEBHOOK_SECRET  — the secret shown when you generated it
YAML;

                            $bitbucket = <<<YAML
# bitbucket-pipelines.yml
#
# suite_id and branch come from Bitbucket pipeline variables so you can
# run the same pipeline file against different suites without editing YAML.
pipelines:
  branches:
    main:
      - step:
          name: Trigger test run
          script:
            - >
              PAYLOAD=\$(jq -cn
              --argjson suite_id "\${SUITE_ID:-{$exampleSuiteId}}"
              --arg branch "\${OVERRIDE_BRANCH:-\$BITBUCKET_BRANCH}"
              '{suite_id: \$suite_id, branch: \$branch}')
            - SIG=\$(echo -n "\$PAYLOAD" | openssl dgst -sha256 -hmac "\$TEST_DASHBOARD_WEBHOOK_SECRET" | awk '{print \$2}')
            - >
              curl -sf -X POST "{$url}"
              -H "Content-Type: application/json"
              -H "X-Webhook-Signature: \$SIG"
              -d "\$PAYLOAD"

# Repository variables required (Settings → Pipelines → Repository variables):
#   TEST_DASHBOARD_WEBHOOK_SECRET  — the secret shown when you generated it
#   SUITE_ID                       — defaults to {$exampleSuiteId} if unset
#   OVERRIDE_BRANCH                — optional; defaults to the triggering branch
YAML;

                            $gitlab = <<<YAML
# .gitlab-ci.yml (job)
#
# suite_id can be overridden per-pipeline via the CI variable SUITE_ID.
trigger_tests:
  stage: .pre
  image: alpine:latest
  variables:
    SUITE_ID: "{$exampleSuiteId}"
    BRANCH: \$CI_COMMIT_REF_NAME
  before_script:
    - apk add --no-cache curl openssl jq
  script:
    - >
      PAYLOAD=\$(jq -cn --argjson suite_id "\$SUITE_ID" --arg branch "\$BRANCH"
      '{suite_id: \$suite_id, branch: \$branch}')
    - SIG=\$(printf '%s' "\$PAYLOAD" | openssl dgst -sha256 -hmac "\$TEST_DASHBOARD_WEBHOOK_SECRET" | awk '{print \$2}')
    - >
      curl -sf -X POST "{$url}"
      -H "Content-Type: application/json"
      -H "X-Webhook-Signature: \$SIG"
      -d "\$PAYLOAD"
  only:
    - main

# CI/CD variable required (Settings → CI/CD → Variables):
#   TEST_DASHBOARD_WEBHOOK_SECRET  — the secret shown when you generated it
YAML;

                            $block = fn (string $heading, string $code) =>
                                "── {$heading} " . str_repeat('─', max(0, 55 - strlen($heading))) . "\n{$code}";

                            $out = [];
                            $out[] = "POST {$url}";
                            $out[] = '';
                            $out[] = 'Suite IDs for this project:';
                            $out[] = $suiteList;
                            $out[] = '';
                            $out[] = 'Payload fields:';
                            $out[] = '  suite_id  (required)  integer — which suite to run';
                            $out[] = '  branch    (optional)  string  — overrides suite/project default';
                            $out[] = '  env       (optional)  object  — key/value env overrides';
                            $out[] = '';
                            $out[] = $block('curl', $curl);
                            $out[] = '';
                            $out[] = $block('GitHub Actions', $githubActions);
                            $out[] = '';
                            $out[] = $block('GitLab CI', $gitlab);
                            $out[] = '';
                            $out[] = $block('Bitbucket Pipelines', $bitbucket);

                            return new \Illuminate\Support\HtmlString(
                                '<pre style="font-size:0.75rem;line-height:1.5;white-space:pre-wrap;word-break:break-all;">'
                                . e(implode("\n", $out))
                                . '</pre>'
                            );
                        }),
                ])
                ->columns(1)
                ->collapsible()
                ->collapsed(fn ($record) => $record === null),

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
                            ->title('Deploy key generated — copy it now!')
                            ->body("This is the only time this key will be shown. Copy it and add it to your repository as a deploy key (read-only access).\n\n" . $keys['public'])
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                Tables\Actions\Action::make('rotate_webhook_secret')
                    ->label(fn (Project $record) => $record->getRawOriginal('webhook_secret') ? 'Rotate Webhook Secret' : 'Generate Webhook Secret')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(fn (Project $record) => $record->getRawOriginal('webhook_secret') ? 'Rotate Webhook Secret?' : 'Generate Webhook Secret?')
                    ->modalDescription(fn (Project $record) => $record->getRawOriginal('webhook_secret')
                        ? 'A new secret will be generated and the existing one will stop working immediately.'
                        : 'Generate a secret to enable webhook triggers for this project.')
                    ->action(function (Project $record) {
                        $secret = $record->generateWebhookSecret();
                        $webhookUrl = route('api.v1.webhook.trigger');
                        Notification::make()
                            ->title('Webhook secret generated — copy it now!')
                            ->body("This is the only time this secret will be shown.\n\nURL: {$webhookUrl}\nSecret: {$secret}\n\nSign payloads with HMAC-SHA256 and send the hex digest in the X-Webhook-Signature header.")
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
