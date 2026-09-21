<?php

namespace App\Providers\Filament;

use App\Filament\Personal\Auth\Register;
use App\Filament\Personal\Pages\PersonalDashboard;
use App\Filament\Personal\Pages\PersonalProfile;
use App\Filament\Shared\Auth\RequestPasswordReset;
use App\Http\Middleware\EnsurePhoneIsVerified;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The owner's personal area at /app: authentication (login, registration,
 * password reset, email verification), the personal dashboard, profile, tax
 * profile and the list of businesses. It has no tenancy; each business is a
 * workspace in the separate "workspace" panel (WorkspacePanelProvider).
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->viteTheme('resources/css/filament/theme.css')
            ->brandName('Settlo')
            ->favicon(asset('images/settlo-icon-32.png'))
            ->login()
            ->registration(Register::class)
            ->passwordReset(RequestPasswordReset::class)
            ->emailVerification()
            ->simplePageMaxContentWidth(Width::TwoExtraLarge)
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->colors(self::colors())
            ->discoverResources(in: app_path('Filament/Personal/Resources'), for: 'App\Filament\Personal\Resources')
            ->discoverPages(in: app_path('Filament/Personal/Pages'), for: 'App\Filament\Personal\Pages')
            ->pages([
                PersonalDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Personal/Widgets'), for: 'App\Filament\Personal\Widgets')
            ->widgets([])
            ->navigationGroups([
                'Overview',
                'Businesses',
                'Personal',
                'Account',
            ])
            ->userMenuItems([
                'profile' => Action::make('profile')
                    ->label('Profile')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->url(fn (): string => PersonalProfile::getUrl(panel: 'app')),
            ])
            ->middleware(self::middleware())
            ->authMiddleware([
                Authenticate::class,
                EnsurePhoneIsVerified::class,
            ]);
    }

    /**
     * Brand colours shared by both owner panels.
     *
     * @return array<string, array<int|string, string>|string>
     */
    public static function colors(): array
    {
        return [
            'primary' => Color::hex('#00A878'),
            'danger' => Color::hex('#E24B4A'),
            'warning' => Color::hex('#F59E0B'),
        ];
    }

    /**
     * The web middleware stack shared by both owner panels.
     *
     * @return list<class-string>
     */
    public static function middleware(): array
    {
        return [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
        ];
    }
}
