<?php

namespace App\Providers\Filament;

use App\Billing\WorkspaceBillingProvider;
use App\Filament\Personal\Pages\Billing;
use App\Filament\Personal\Pages\EditTaxProfile;
use App\Filament\Personal\Pages\PersonalDashboard;
use App\Filament\Personal\Pages\PersonalProfile;
use App\Filament\Personal\Pages\SetUpBusiness;
use App\Filament\Workspace\Pages\Dashboard;
use App\Http\Middleware\AuthenticateWorkspace;
use App\Http\Middleware\EnsurePhoneIsVerified;
use App\Http\Middleware\RememberLastWorkspace;
use App\Models\BusinessEntity;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;

/**
 * A business workspace at /app/w/{businessEntity}. The tenant is one of the
 * owner's businesses; authentication (login, registration, verification) lives
 * in the personal "app" panel, so this panel has no auth pages of its own.
 */
class WorkspacePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('workspace')
            ->path('app/w')
            ->viteTheme('resources/css/filament/theme.css')
            ->brandName('Settlo')
            ->favicon(asset('images/settlo-icon-32.png'))
            ->tenant(BusinessEntity::class)
            ->tenantBillingProvider(new WorkspaceBillingProvider)
            ->requiresTenantSubscription()
            ->tenantMiddleware([
                RememberLastWorkspace::class,
            ], isPersistent: true)
            ->tenantMenuItems([
                Action::make('allBusinesses')
                    ->label('All businesses')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->url(fn (): string => PersonalDashboard::getUrl(panel: 'app')),
                Action::make('setUpBusiness')
                    ->label('Set up another business')
                    ->icon(Heroicon::OutlinedPlusCircle)
                    ->url(fn (): string => SetUpBusiness::getUrl(panel: 'app')),
                // Named "billing" so it replaces Filament's default tenant
                // billing item instead of showing "Billing" twice.
                Action::make('billing')
                    ->label('Billing')
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->url(fn (): string => Billing::getUrl(['workspace' => Filament::getTenant()?->getKey()], panel: 'app')),
            ])
            ->renderHook(PanelsRenderHook::CONTENT_START, fn (): string => self::subscriptionEndedBanner())
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->colors(AppPanelProvider::colors())
            ->discoverResources(in: app_path('Filament/Workspace/Resources'), for: 'App\Filament\Workspace\Resources')
            ->discoverPages(in: app_path('Filament/Workspace/Pages'), for: 'App\Filament\Workspace\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Workspace/Widgets'), for: 'App\Filament\Workspace\Widgets')
            ->widgets([])
            ->navigationGroups([
                'Overview',
                'Finance',
                'Insights',
                'Support',
                'Settings',
            ])
            ->navigationItems([
                NavigationItem::make('All businesses')
                    ->url(fn (): string => PersonalDashboard::getUrl(panel: 'app'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->group('Overview')
                    ->sort(-100),
            ])
            ->userMenuItems([
                Action::make('personalProfile')
                    ->label('Personal profile')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->url(fn (): string => PersonalProfile::getUrl(panel: 'app')),
                Action::make('taxProfile')
                    ->label('Tax profile')
                    ->icon(Heroicon::OutlinedCalculator)
                    ->url(fn (): string => EditTaxProfile::getUrl(panel: 'app')),
            ])
            ->middleware(AppPanelProvider::middleware())
            ->authMiddleware([
                AuthenticateWorkspace::class,
                'verified:filament.app.auth.email-verification.prompt',
                EnsurePhoneIsVerified::class,
            ]);
    }

    /**
     * An amber bar on every workspace page while the workspace is read-only
     * (subscription expired or cancelled).
     */
    public static function subscriptionEndedBanner(): string
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof BusinessEntity || $tenant->canWrite()) {
            return '';
        }

        return view('filament.workspace.partials.subscription-ended-banner', [
            'entity' => $tenant,
            'billingUrl' => Billing::getUrl(['workspace' => $tenant->getKey()], panel: 'app'),
        ])->render();
    }
}
