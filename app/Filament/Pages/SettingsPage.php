<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Filament\Pages;

use App\Jobs\MigrateArtifactsToS3Job;
use App\Models\AppSetting;
use App\Models\TestRun;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SettingsPage extends Page
{
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'General';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 1;
    protected static string $view = 'filament.pages.settings';
    protected static ?string $title = 'General Settings';
    protected static ?string $slug = 'settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'notifications_enabled' => AppSetting::get('notifications_enabled', '1') === '1',
            's3_bucket'             => AppSetting::get('s3_bucket'),
            's3_region'             => AppSetting::get('s3_region'),
            's3_key'                => AppSetting::get('s3_key'),
            // Never expose the real secret — use a sentinel so the field shows as set.
            // The save() method only writes a new value when the field is non-empty and changed.
            's3_secret'             => AppSetting::get('s3_secret') ? '••••••••••••••••' : null,
            's3_endpoint'           => AppSetting::get('s3_endpoint'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Notifications')
                    ->icon('heroicon-o-bell')
                    ->schema([
                        Forms\Components\Toggle::make('notifications_enabled')
                            ->label('Send email notifications when a test run completes')
                            ->helperText('Emails are sent to the user who triggered the test run.')
                            ->default(true),
                    ]),

                Forms\Components\Section::make('S3 / Remote Storage')
                    ->icon('heroicon-o-cloud')
                    ->description('Configure S3-compatible storage (AWS S3, Cloudflare R2, MinIO). Leave blank to use local disk.')
                    ->schema([
                        Forms\Components\TextInput::make('s3_bucket')
                            ->label('Bucket Name')
                            ->placeholder('my-test-artifacts'),
                        Forms\Components\TextInput::make('s3_region')
                            ->label('Region')
                            ->placeholder('us-east-1'),
                        Forms\Components\TextInput::make('s3_key')
                            ->label('Access Key')
                            ->password()
                            ->revealable(),
                        Forms\Components\TextInput::make('s3_secret')
                            ->label('Secret Key')
                            ->password()
                            ->revealable(),
                        Forms\Components\TextInput::make('s3_endpoint')
                            ->label('Endpoint (optional)')
                            ->placeholder('https://s3.example.com — leave blank for AWS S3'),
                        Forms\Components\Toggle::make('s3_use_path_style')
                            ->label('Use path-style endpoint')
                            ->helperText('Required for Cloudflare R2 and some MinIO setups.'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        AppSetting::set('notifications_enabled', $data['notifications_enabled'] ? '1' : '0');

        if (!empty($data['s3_bucket'])) {
            AppSetting::set('s3_bucket',         $data['s3_bucket']);
            AppSetting::set('s3_region',         $data['s3_region']);
            AppSetting::set('s3_key',            $data['s3_key']);
            AppSetting::set('s3_endpoint',       $data['s3_endpoint'] ?? '');
            AppSetting::set('s3_use_path_style', $data['s3_use_path_style'] ? '1' : '0');

            // Only overwrite stored secret if the user typed a new value (not the sentinel).
            if (!empty($data['s3_secret']) && $data['s3_secret'] !== '••••••••••••••••') {
                AppSetting::set('s3_secret', $data['s3_secret']);
            }
        }

        Notification::make()->title('Settings saved')->success()->send();
    }

    public function getHeaderActions(): array
    {
        $s3Configured     = (bool) AppSetting::get('s3_bucket');
        $migrationRunning = AppSetting::get('s3_migration_running') === '1';
        $pendingCount     = $s3Configured ? $this->pendingMigrationCount() : 0;

        if (!$s3Configured || $pendingCount === 0) {
            return [];
        }

        return [
            Action::make('migrate_to_s3')
                ->label($migrationRunning ? 'Migration in progress…' : "Migrate {$pendingCount} run(s) to S3")
                ->icon($migrationRunning ? 'heroicon-o-arrow-path' : 'heroicon-o-cloud-arrow-up')
                ->color('warning')
                ->disabled($migrationRunning)
                ->requiresConfirmation()
                ->modalHeading('Migrate existing artifacts to S3')
                ->modalDescription(
                    "This will copy artifacts from {$pendingCount} run(s) from local disk to S3. " .
                    "Depending on the volume of data this may take a significant amount of time and will run as a background job. " .
                    "Existing local files will not be deleted automatically — you can clean them up manually after verifying the migration succeeded."
                )
                ->modalSubmitActionLabel('Queue migration')
                ->action(function () {
                    AppSetting::set('s3_migration_running', '1');
                    MigrateArtifactsToS3Job::dispatch();
                    Notification::make()
                        ->title('Migration queued')
                        ->body('Artifacts will be migrated to S3 in the background.')
                        ->success()
                        ->send();
                }),
        ];
    }

    private function pendingMigrationCount(): int
    {
        return TestRun::where(function ($q) {
                $q->whereNull('storage_disk')
                  ->orWhere('storage_disk', '!=', 's3');
            })
            ->whereNotNull('report_html_path')
            ->count();
    }
}
