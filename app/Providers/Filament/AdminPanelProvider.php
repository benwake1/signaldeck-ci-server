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
                'primary' => Color::Blue,
                'success' => Color::Green,
                'danger'  => Color::Red,
                'warning' => Color::Amber,
                'info'    => Color::Sky,
            ])
            ->brandName(config('app.name'))
            ->brandLogo(null)
            ->favicon(null)
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
                    ->registration(false)
                    ->createUserUsing(function (string $provider, \Laravel\Socialite\Contracts\User $oauthUser, FilamentSocialitePlugin $plugin) {
                        // Only allow sign-in for users that already exist in the DB
                        // (matched by email). New accounts must be created by an admin.
                        $user = \App\Models\User::where('email', $oauthUser->getEmail())->first();

                        if (! $user) {
                            throw new \DomainException('No account found for this Google address. Please contact an administrator.');
                        }

                        return $user;
                    }),
            ]);
    }
}
