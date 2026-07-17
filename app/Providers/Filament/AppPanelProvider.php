<?php

namespace App\Providers\Filament;

use App\Filament\App\Auth\Register;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Tenancy\RegisterBusinessEntity;
use App\Models\BusinessEntity;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->brandName('Settlo')
            ->login()
            ->registration(Register::class)
            ->passwordReset()
            ->tenant(BusinessEntity::class)
            ->tenantRegistration(RegisterBusinessEntity::class)
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->colors([
                'primary' => Color::hex('#00A878'),
                'danger' => Color::hex('#E24B4A'),
                'warning' => Color::hex('#F59E0B'),
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\Filament\App\Widgets')
            ->widgets([])
            ->navigationGroups([
                'Overview',
                'Finance',
                'Insights',
                'Support',
                'Settings',
            ])
            ->navigationItems([
                NavigationItem::make('Ask Settlo')
                    ->group('Support')
                    ->icon(Heroicon::ChatBubbleLeftRight)
                    ->url(fn (): string => route('ask-settlo.index', Filament::getTenant()), shouldOpenInNewTab: false)
                    ->isActiveWhen(fn (): bool => request()->routeIs('ask-settlo.*')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
