<?php

/**
 * Copyright (c) 2026 Ben Wake
 *
 * This source code is licensed under the MIT License.
 * See the LICENSE file for details.
 */

namespace App\Providers\Filament;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TestRunResource;
use App\Filament\Widgets\ProjectHealthWidget;
use App\Filament\Widgets\RecentRunsWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Services\SsoConfigService;
use DutchCodingCompany\FilamentSocialite\FilamentSocialitePlugin;
use DutchCodingCompany\FilamentSocialite\Provider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $brand = $this->resolveBrand();

        $panel = $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => $brand['primary_color'] ? Color::hex($brand['primary_color']) : Color::Blue,
                'success' => Color::Green,
                'danger'  => Color::Red,
                'warning' => Color::Amber,
                'info'    => Color::Sky,
            ])
            ->brandName($brand['name'] ?: config('app.name'))
            ->brandLogo(new \Illuminate\Support\HtmlString(view('filament.brand-logo', ['brand' => $brand])->render()))
            ->brandLogoHeight($brand['logo_height'])
            ->favicon($brand['favicon_url'])
            ->darkMode(true, isForced: config('brand.is_hosted'))
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn() => config('brand.is_hosted')
                    ? '<script>document.body.classList.add("signaldeck-hosted")</script>'
                    : '',
            )
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn() => config('brand.is_hosted')
                    // Force dark mode: add .dark to <html> immediately (prevents FOUC),
                    // then set localStorage 'theme' = 'dark' so Alpine reads it on init
                    // and its effect keeps .dark rather than removing it.
                    ? '<script>document.documentElement.classList.add("dark");try{localStorage.setItem("theme","dark")}catch(e){}</script>'
                    : '',
            )
            ->renderHook(
                PanelsRenderHook::FOOTER,
                fn() => view('filament.footer', ['brand' => $brand]),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                function () use ($brand) {
                    $primary   = $brand['primary_color']   ?? '#39d5ff';
                    $secondary = $brand['secondary_color'] ?? '#20bce7';
                    $hosted = config('brand.is_hosted') ? '--default-theme-mode:dark;' : '';
                    $out = "<style>:root{{$hosted}--brand-primary:{$primary};--brand-secondary:{$secondary};}</style>";
                    // Colour utilities shared across all themes — fixes Filament's dynamically
                    // constructed class names (e.g. 'bg-' . $color . '-50') that Tailwind never sees
                    $out .= app(\Illuminate\Foundation\Vite::class)('resources/css/filament-utilities.css');
                    if (config('brand.is_hosted')) {
                        // Fonts
                        $out .= '<link rel="preconnect" href="https://fonts.bunny.net">'
                              . '<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|jetbrains-mono:400,500&display=swap" rel="stylesheet">';
                        // Theme stylesheet — loaded after Filament's own CSS so our overrides win
                        $out .= app(\Illuminate\Foundation\Vite::class)('resources/css/signaldeck-theme.css');
                    }
                    return $out;
                },
            )
            ->navigationGroups([
                NavigationGroup::make('Testing')
                    ->icon('heroicon-o-beaker'),
                NavigationGroup::make('Management')
                    ->icon('heroicon-o-building-office'),
                NavigationGroup::make('Settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                StatsOverviewWidget::class,
                ProjectHealthWidget::class,
                RecentRunsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);

        // ── SSO Providers (admin-controllable) ───────────────────────────
        $ssoPlugin = $this->buildSsoPlugin();

        if ($ssoPlugin) {
            $panel->plugins([$ssoPlugin]);
        }

        return $panel;
    }

    private function resolveBrand(): array
    {
        if (config('brand.is_hosted')) {
            return [
                'name'            => 'SignalDeck CI',
                'legal_name'      => 'SignalDeck',
                'primary_color'   => '#39d5ff',
                'secondary_color' => '#20bce7',
                'logo_url'        => asset('images/brand-symbol.png'),
                'logo_dark_url'   => asset('images/brand-symbol.png'),
                'logo_height'     => '2rem',
                'favicon_url'     => asset('images/brand-symbol.png'),
            ];
        }

        return Cache::remember('brand_settings', 300, function () {
            try {
                $rows = \App\Models\AppSetting::whereIn('key', [
                    'brand_name', 'brand_legal_name',
                    'brand_primary_color', 'brand_secondary_color',
                    'brand_logo_path', 'brand_logo_dark_path',
                    'brand_logo_height', 'brand_favicon_path',
                ])->pluck('value', 'key');

                // DB paths are disk-relative (e.g. 'brand/logo.png') — resolve via storage
                // Env fallback paths are public/-relative — resolve via asset()
                $logoPath     = $rows['brand_logo_path']     ?: null;
                $logoDarkPath = $rows['brand_logo_dark_path'] ?: null;
                $faviconPath  = $rows['brand_favicon_path']  ?: null;

                $resolveStorageUrl = fn(?string $path) => $path
                    ? Storage::disk('public')->url($path)
                    : null;

                // Env fallbacks use asset() since they're public/-relative
                $envLogoUrl     = config('brand.logo_path')      ? asset(config('brand.logo_path'))      : null;
                $envLogoDarkUrl = config('brand.logo_dark_path') ? asset(config('brand.logo_dark_path')) : null;
                $envFaviconUrl  = config('brand.favicon_path')   ? asset(config('brand.favicon_path'))   : null;

                return [
                    'name'            => $rows['brand_name']            ?: config('brand.name'),
                    'legal_name'      => $rows['brand_legal_name']      ?: config('brand.legal_name'),
                    'primary_color'   => $rows['brand_primary_color']   ?: config('brand.primary_color') ?: null,
                    'secondary_color' => $rows['brand_secondary_color'] ?: config('brand.secondary_color') ?: null,
                    'logo_url'        => $resolveStorageUrl($logoPath)     ?: $envLogoUrl,
                    'logo_dark_url'   => $resolveStorageUrl($logoDarkPath) ?: $resolveStorageUrl($logoPath) ?: $envLogoDarkUrl ?: $envLogoUrl,
                    'logo_height'     => $rows['brand_logo_height']     ?: config('brand.logo_height', '2rem'),
                    'favicon_url'     => $resolveStorageUrl($faviconPath)  ?: $envFaviconUrl,
                ];
            } catch (\Throwable) {
                return [
                    'name'            => config('brand.name'),
                    'legal_name'      => config('brand.legal_name'),
                    'primary_color'   => config('brand.primary_color') ?: null,
                    'secondary_color' => config('brand.secondary_color') ?: null,
                    'logo_url'        => config('brand.logo_path')      ? asset(config('brand.logo_path'))      : null,
                    'logo_dark_url'   => config('brand.logo_dark_path') ? asset(config('brand.logo_dark_path')) : null,
                    'logo_height'     => config('brand.logo_height', '2rem'),
                    'favicon_url'     => config('brand.favicon_path')   ? asset(config('brand.favicon_path'))   : null,
                ];
            }
        });
    }

    /**
     * Build the FilamentSocialitePlugin with only the admin-enabled providers.
     * Returns null if no providers are active (hides SSO buttons entirely).
     */
    private function buildSsoPlugin(): ?FilamentSocialitePlugin
    {
        try {
            $sso = app(SsoConfigService::class);
        } catch (\Throwable) {
            // DB not available (migrations, first install) — fall back to .env config
            return $this->buildEnvFallbackPlugin();
        }

        // If the admin has never visited SSO settings, no DB rows exist yet.
        // Fall back to .env so existing installs keep working until configured via UI.
        if (! $sso->hasDbSettings()) {
            return $this->buildEnvFallbackPlugin();
        }

        // DB settings exist — admin has explicitly configured SSO.
        // Only show providers they've enabled. If all are off, show nothing.
        $activeProviders = $sso->getActiveProviders();

        if (empty($activeProviders)) {
            return null;
        }

        $providers = [];

        foreach ($activeProviders as $providerName) {
            $meta = SsoConfigService::PROVIDERS[$providerName] ?? null;
            if (! $meta) continue;

            $providers[] = Provider::make($providerName)
                ->label($meta['label'])
                ->color(Color::hex($meta['color']))
                ->outlined(true);
        }

        if (empty($providers)) {
            return null;
        }

        return FilamentSocialitePlugin::make()
            ->providers($providers)
            ->registration(true)
            ->createUserUsing(function (string $provider, \Laravel\Socialite\Contracts\User $oauthUser, FilamentSocialitePlugin $plugin) {
                $user = \App\Models\User::firstOrCreate(
                    ['email' => $oauthUser->getEmail()],
                    [
                        'name'       => $oauthUser->getName(),
                        'avatar_url' => $oauthUser->getAvatar(),
                        'password'   => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
                    ],
                );
                // Refresh avatar on every login — provider URLs can rotate
                $user->update(['avatar_url' => $oauthUser->getAvatar()]);
                return $user;
            });
    }

    /**
     * Backwards-compatible fallback: use .env credentials if no DB settings exist yet.
     * This keeps Google SSO working for existing installs before the admin
     * has visited the new Settings page.
     */
    private function buildEnvFallbackPlugin(): ?FilamentSocialitePlugin
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return null;
        }

        return FilamentSocialitePlugin::make()
            ->providers([
                Provider::make('google')
                    ->label('Google')
                    ->color(Color::hex('#4285F4'))
                    ->outlined(true),
            ])
            ->registration(true)
            ->createUserUsing(function (string $provider, \Laravel\Socialite\Contracts\User $oauthUser, FilamentSocialitePlugin $plugin) {
                $user = \App\Models\User::firstOrCreate(
                    ['email' => $oauthUser->getEmail()],
                    [
                        'name'       => $oauthUser->getName(),
                        'avatar_url' => $oauthUser->getAvatar(),
                        'password'   => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
                    ],
                );
                $user->update(['avatar_url' => $oauthUser->getAvatar()]);
                return $user;
            });
    }
}
