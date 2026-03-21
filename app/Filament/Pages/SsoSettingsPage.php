<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HandlesSecretFields;
use App\Models\AppSetting;
use App\Services\SsoConfigService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SsoSettingsPage extends Page
{
    use HandlesSecretFields;

    protected static ?string $navigationIcon = null;
    protected static ?string $navigationLabel = 'Single Sign-On';
    protected static ?string $navigationGroup = 'Settings';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.sso-settings';
    protected static ?string $title = 'Single Sign-On (SSO)';
    protected static ?string $slug = 'settings/sso';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $sso = app(SsoConfigService::class);

        // If the admin has never configured SSO via the UI, pre-fill from .env
        // so the toggle reflects what's actually running on the login page.
        $envFallback = ! $sso->hasDbSettings();

        $this->form->fill([
            // ── Google ──
            'sso_google_enabled'       => $envFallback
                ? (config('services.google.client_id') && config('services.google.client_secret'))
                : $sso->isProviderEnabled('google'),
            'sso_google_client_id'     => $sso->getClientId('google') ?: config('services.google.client_id', ''),
            'sso_google_client_secret' => $this->maskSecret('sso_google_client_secret')
                ?: ($envFallback && config('services.google.client_secret') ? self::SECRET_PLACEHOLDER : ''),

            // ── GitHub ──
            'sso_github_enabled'       => $envFallback
                ? (config('services.github.client_id') && config('services.github.client_secret'))
                : $sso->isProviderEnabled('github'),
            'sso_github_client_id'     => $sso->getClientId('github') ?: config('services.github.client_id', ''),
            'sso_github_client_secret' => $this->maskSecret('sso_github_client_secret')
                ?: ($envFallback && config('services.github.client_secret') ? self::SECRET_PLACEHOLDER : ''),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('SSO Providers')
                    ->description('Enable social login providers on the login page. Credentials are stored encrypted.')
                    ->icon('heroicon-o-key')
                    ->schema([
                        // ── Google ──
                        Forms\Components\Fieldset::make('Google')
                            ->schema([
                                Forms\Components\Toggle::make('sso_google_enabled')
                                    ->label('Enable Google SSO')
                                    ->helperText('Allow users to sign in with their Google account.')
                                    ->live()
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('sso_google_client_id')
                                    ->label('Client ID')
                                    ->placeholder('xxxx.apps.googleusercontent.com')
                                    ->visible(fn (Forms\Get $get) => $get('sso_google_enabled'))
                                    ->required(fn (Forms\Get $get) => $get('sso_google_enabled')),

                                Forms\Components\TextInput::make('sso_google_client_secret')
                                    ->label('Client Secret')
                                    ->password()
                                    ->placeholder('GOCSPX-...')
                                    ->visible(fn (Forms\Get $get) => $get('sso_google_enabled'))
                                    ->requiredUnless('sso_google_client_secret', self::SECRET_PLACEHOLDER)
                                    ->helperText(fn (Forms\Get $get) => $get('sso_google_client_secret') === self::SECRET_PLACEHOLDER
                                        ? 'A secret is saved. Clear the field and type a new one to change it.'
                                        : null),

                                Forms\Components\Placeholder::make('sso_google_redirect')
                                    ->label('Redirect URI')
                                    ->content(fn () => url('/admin/oauth/callback/google'))
                                    ->helperText('Add this URI to your Google Cloud Console → Authorized redirect URIs.')
                                    ->visible(fn (Forms\Get $get) => $get('sso_google_enabled'))
                                    ->columnSpanFull(),
                            ])->columns(2),

                        // ── GitHub ──
                        Forms\Components\Fieldset::make('GitHub')
                            ->schema([
                                Forms\Components\Toggle::make('sso_github_enabled')
                                    ->label('Enable GitHub SSO')
                                    ->helperText('Allow users to sign in with their GitHub account.')
                                    ->live()
                                    ->columnSpanFull(),

                                Forms\Components\TextInput::make('sso_github_client_id')
                                    ->label('Client ID')
                                    ->placeholder('Iv1.abc123...')
                                    ->visible(fn (Forms\Get $get) => $get('sso_github_enabled'))
                                    ->required(fn (Forms\Get $get) => $get('sso_github_enabled')),

                                Forms\Components\TextInput::make('sso_github_client_secret')
                                    ->label('Client Secret')
                                    ->password()
                                    ->placeholder('github_secret_...')
                                    ->visible(fn (Forms\Get $get) => $get('sso_github_enabled'))
                                    ->requiredUnless('sso_github_client_secret', self::SECRET_PLACEHOLDER)
                                    ->helperText(fn (Forms\Get $get) => $get('sso_github_client_secret') === self::SECRET_PLACEHOLDER
                                        ? 'A secret is saved. Clear the field and type a new one to change it.'
                                        : null),

                                Forms\Components\Placeholder::make('sso_github_redirect')
                                    ->label('Callback URL')
                                    ->content(fn () => url('/admin/oauth/callback/github'))
                                    ->helperText('Add this URL to your GitHub OAuth App → Authorization callback URL.')
                                    ->visible(fn (Forms\Get $get) => $get('sso_github_enabled'))
                                    ->columnSpanFull(),
                            ])->columns(2),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $sso = app(SsoConfigService::class);
        $wasFallback = ! $sso->hasDbSettings();

        foreach (array_keys(SsoConfigService::PROVIDERS) as $provider) {
            AppSetting::set("sso_{$provider}_enabled", ($data["sso_{$provider}_enabled"] ?? false) ? '1' : '0');
            $sso->setCredential("sso_{$provider}_client_id", $data["sso_{$provider}_client_id"] ?? '');

            $secret = $data["sso_{$provider}_client_secret"] ?? '';

            // First save with .env fallback active: the placeholder represents
            // an .env value, not a DB value. Copy the .env secret into the DB
            // so credentials are preserved when the fallback stops being used.
            if ($wasFallback && $secret === self::SECRET_PLACEHOLDER) {
                $envSecret = config("services.{$provider}.client_secret", '');
                if ($envSecret !== '') {
                    $this->saveSecretIfChanged("sso_{$provider}_client_secret", $envSecret);
                    continue;
                }
            }

            $this->saveSecretIfChanged("sso_{$provider}_client_secret", $secret);
        }

        $sso->applyRuntimeConfig();

        Notification::make()
            ->title('SSO settings saved')
            ->success()
            ->send();
    }
}
