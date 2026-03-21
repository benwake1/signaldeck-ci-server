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

        $this->form->fill([
            // ── Google ──
            'sso_google_enabled'       => $sso->isProviderEnabled('google'),
            'sso_google_client_id'     => $sso->getClientId('google'),
            'sso_google_client_secret' => $this->maskSecret('sso_google_client_secret'),

            // ── GitHub ──
            'sso_github_enabled'       => $sso->isProviderEnabled('github'),
            'sso_github_client_id'     => $sso->getClientId('github'),
            'sso_github_client_secret' => $this->maskSecret('sso_github_client_secret'),
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

        foreach (array_keys(SsoConfigService::PROVIDERS) as $provider) {
            AppSetting::set("sso_{$provider}_enabled", ($data["sso_{$provider}_enabled"] ?? false) ? '1' : '0');
            $sso->setCredential("sso_{$provider}_client_id", $data["sso_{$provider}_client_id"] ?? '');
            $this->saveSecretIfChanged("sso_{$provider}_client_secret", $data["sso_{$provider}_client_secret"] ?? '');
        }

        $sso->applyRuntimeConfig();

        Notification::make()
            ->title('SSO settings saved')
            ->success()
            ->send();
    }
}
