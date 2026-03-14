<?php

namespace App\Providers\Filament;

use App\Filament\Resources\ClientResource;
use App\Filament\Resources\ProjectResource;
use App\Filament\Resources\TestRunResource;
use App\Filament\Widgets\ProjectHealthWidget;
use App\Filament\Widgets\RecentRunsWidget;
use App\Filament\Widgets\StatsOverviewWidget;
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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
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
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => env('BRAND_PRIMARY_COLOR') ? Color::hex(env('BRAND_PRIMARY_COLOR')) : Color::Blue,
                'success' => Color::Green,
                'danger'  => Color::Red,
                'warning' => Color::Amber,
                'info'    => Color::Sky,
            ])
            ->brandName(env('BRAND_NAME') ?: config('app.name'))
            ->brandLogo(env('BRAND_LOGO_PATH') ? asset(env('BRAND_LOGO_PATH')) : null)
            ->darkModeBrandLogo(asset(env('BRAND_LOGO_DARK_PATH') ?: env('BRAND_LOGO_PATH') ?: '')  ?: null)
            ->brandLogoHeight(env('BRAND_LOGO_HEIGHT', '2rem'))
            ->favicon(env('BRAND_FAVICON_PATH') ? asset(env('BRAND_FAVICON_PATH')) : null)
            ->darkMode(true)
            ->navigationGroups([
                NavigationGroup::make('Testing')
                    ->icon('heroicon-o-beaker'),
                NavigationGroup::make('Management')
                    ->icon('heroicon-o-building-office'),
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
            ->authMiddleware([Authenticate::class])
            ->plugins([
                FilamentSocialitePlugin::make()
                    ->providers([
                        Provider::make('google')
                            ->label('Google')
                            ->color(\Filament\Support\Colors\Color::hex('#4285F4'))
                            ->outlined(true),
                    ])
                    ->registration(true)
                    // TODO: restrict to a specific email domain, e.g.:
                    // ->domainAllowList(['yourdomain.com'])
                    ->createUserUsing(function (string $provider, \Laravel\Socialite\Contracts\User $oauthUser, FilamentSocialitePlugin $plugin) {
                        // Find existing user by email, or create a new one via Google SSO.
                        return \App\Models\User::firstOrCreate(
                            ['email' => $oauthUser->getEmail()],
                            [
                                'name'     => $oauthUser->getName(),
                                'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
                            ],
                        );
                    }),
            ]);
    }
}
