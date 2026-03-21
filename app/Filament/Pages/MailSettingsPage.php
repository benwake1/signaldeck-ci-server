<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HandlesSecretFields;
use App\Models\AppSetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;

class MailSettingsPage extends Page
{
    use HandlesSecretFields;

    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Mail';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.pages.mail-settings';
    protected static ?string $title = 'Mail Settings';
    protected static ?string $slug = 'settings/mail';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'mail_from_address' => AppSetting::get('mail_from_address', config('mail.from.address')),
            'mail_from_name'    => AppSetting::get('mail_from_name', config('mail.from.name')),
            'mail_mailer'       => AppSetting::get('mail_mailer', config('mail.default')),
            'mail_host'         => AppSetting::get('mail_host', config('mail.mailers.smtp.host')),
            'mail_port'         => AppSetting::get('mail_port', config('mail.mailers.smtp.port')),
            'mail_username'     => AppSetting::get('mail_username', config('mail.mailers.smtp.username')),
            'mail_password'     => $this->maskSecret('mail_password'),
            'mail_encryption'   => AppSetting::get('mail_encryption', config('mail.mailers.smtp.encryption')),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Mail Configuration')
                    ->description('Overrides values from .env. Leave blank to use .env defaults.')
                    ->icon('heroicon-o-envelope')
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

                        Forms\Components\TextInput::make('mail_password')
                            ->label('SMTP Password / API Key')
                            ->password()
                            ->placeholder('Enter password or API key')
                            ->helperText(fn (Forms\Get $get) => $get('mail_password') === self::SECRET_PLACEHOLDER
                                ? 'A password is saved. Clear the field and type a new one to change it.'
                                : null),

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

        AppSetting::set('mail_from_address', $data['mail_from_address'] ?? '');
        AppSetting::set('mail_from_name', $data['mail_from_name'] ?? '');
        AppSetting::set('mail_mailer', $data['mail_mailer'] ?? '');
        AppSetting::set('mail_host', $data['mail_host'] ?? '');
        AppSetting::set('mail_port', $data['mail_port'] ?? '');
        AppSetting::set('mail_username', $data['mail_username'] ?? '');
        $this->saveSecretIfChanged('mail_password', $data['mail_password'] ?? '');
        AppSetting::set('mail_encryption', $data['mail_encryption'] ?? '');

        Notification::make()
            ->title('Mail settings saved')
            ->success()
            ->send();
    }

    public function sendTestEmail(): void
    {
        $this->save();

        $user = auth()->user();
        if (! $user?->email) {
            Notification::make()
                ->title('No email address found for your account')
                ->danger()
                ->send();
            return;
        }

        $this->applyMailSettings();
        Mail::purge(config('mail.default'));

        try {
            Mail::raw(
                'This is a test email from your Cypress Dashboard to confirm mail settings are working correctly.',
                function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Cypress Dashboard — Mail Configuration Test');
                }
            );

            Notification::make()
                ->title('Test email sent')
                ->body("Delivered to {$user->email}. Check your inbox.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Failed to send test email')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    private function applyMailSettings(): void
    {
        $map = [
            'mail_mailer'       => 'mail.default',
            'mail_from_address' => 'mail.from.address',
            'mail_from_name'    => 'mail.from.name',
            'mail_host'         => 'mail.mailers.smtp.host',
            'mail_port'         => 'mail.mailers.smtp.port',
            'mail_username'     => 'mail.mailers.smtp.username',
            'mail_password'     => 'mail.mailers.smtp.password',
            'mail_encryption'   => 'mail.mailers.smtp.encryption',
        ];

        foreach ($map as $setting => $configKey) {
            $value = AppSetting::get($setting);

            if ($setting === 'mail_password' && $value) {
                try {
                    $value = Crypt::decryptString($value);
                } catch (\Exception) {
                    // Stored before encryption was added — use as-is
                }
            }

            if ($value !== null && $value !== '') {
                Config::set($configKey, $value);
            }
        }
    }
}
