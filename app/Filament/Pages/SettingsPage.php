<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SettingsPage extends Page
{
    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Settings';
    protected static ?string $navigationGroup = 'Management';
    protected static ?int $navigationSort = 99;
    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'notifications_enabled'  => AppSetting::get('notifications_enabled', '1') === '1',
            'mail_from_address'      => AppSetting::get('mail_from_address', config('mail.from.address')),
            'mail_from_name'         => AppSetting::get('mail_from_name', config('mail.from.name')),
            'mail_mailer'            => AppSetting::get('mail_mailer', config('mail.default')),
            'mail_host'              => AppSetting::get('mail_host', config('mail.mailers.smtp.host')),
            'mail_port'              => AppSetting::get('mail_port', config('mail.mailers.smtp.port')),
            'mail_username'          => AppSetting::get('mail_username', config('mail.mailers.smtp.username')),
            'mail_encryption'        => AppSetting::get('mail_encryption', config('mail.mailers.smtp.encryption')),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Notifications')
                    ->schema([
                        Forms\Components\Toggle::make('notifications_enabled')
                            ->label('Send email notifications when a test run completes')
                            ->helperText('Emails are sent to the user who triggered the test run.')
                            ->default(true),
                    ]),

                Forms\Components\Section::make('Mail Configuration')
                    ->description('Overrides values from .env. Leave blank to use .env defaults.')
                    ->schema([
                        Forms\Components\Select::make('mail_mailer')
                            ->label('Mail Driver')
                            ->options([
                                'smtp'     => 'SMTP',
                                'sendgrid' => 'SendGrid',
                                'mailgun'  => 'Mailgun',
                                'ses'      => 'Amazon SES',
                                'log'      => 'Log (testing)',
                            ])
                            ->default('smtp'),

                        Forms\Components\TextInput::make('mail_from_address')
                            ->label('From Address')
                            ->email(),

                        Forms\Components\TextInput::make('mail_from_name')
                            ->label('From Name'),

                        Forms\Components\TextInput::make('mail_host')
                            ->label('SMTP Host')
                            ->placeholder('smtp.example.com'),

                        Forms\Components\TextInput::make('mail_port')
                            ->label('SMTP Port')
                            ->numeric()
                            ->placeholder('587'),

                        Forms\Components\TextInput::make('mail_username')
                            ->label('SMTP Username'),

                        Forms\Components\TextInput::make('mail_encryption')
                            ->label('Encryption')
                            ->placeholder('tls'),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        AppSetting::set('notifications_enabled', $data['notifications_enabled'] ? '1' : '0');
        AppSetting::set('mail_from_address', $data['mail_from_address'] ?? '');
        AppSetting::set('mail_from_name', $data['mail_from_name'] ?? '');
        AppSetting::set('mail_mailer', $data['mail_mailer'] ?? '');
        AppSetting::set('mail_host', $data['mail_host'] ?? '');
        AppSetting::set('mail_port', $data['mail_port'] ?? '');
        AppSetting::set('mail_username', $data['mail_username'] ?? '');
        AppSetting::set('mail_encryption', $data['mail_encryption'] ?? '');

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
