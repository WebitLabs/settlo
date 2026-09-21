<?php

namespace App\Filament\Personal\Widgets;

use App\Filament\Personal\Pages\Billing;
use App\Filament\Personal\Pages\SetUpBusiness;
use App\Filament\Support\SubscriptionBadge;
use App\Filament\Workspace\Pages\Dashboard;
use App\Models\BusinessEntity;
use App\Models\User;
use App\Services\Reporting\BusinessMetrics;
use App\Services\Reporting\BusinessMetricsResult;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * One card per business workspace on the personal dashboard: type,
 * subscription state, plan, year-to-date figures and links into the workspace
 * and its billing. Ends with a "Set up another business" card.
 */
class WorkspacesOverview extends Widget
{
    protected string $view = 'filament.personal.widgets.workspaces-overview';

    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Filament::auth()->user()?->isOwner() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = Filament::auth()->user();

        $businesses = $user instanceof User
            ? $user->ownedEntities()->with('subscription.plan')->orderBy('name')->get()
            : collect();

        $metrics = app(BusinessMetrics::class)->forEntityIds($businesses->modelKeys());

        return [
            'workspaces' => $businesses->map(function (BusinessEntity $business) use ($metrics): array {
                $subscription = $business->subscription;
                $figures = $metrics->get($business->getKey()) ?? new BusinessMetricsResult;

                return [
                    'name' => $business->name,
                    'type' => $business->type?->getLabel(),
                    'badge' => SubscriptionBadge::for($subscription),
                    'plan' => $subscription?->plan !== null
                        ? $subscription->plan->name.' · '.($subscription->billing_interval?->getLabel() ?? '')
                        : null,
                    'revenue' => $figures->revenueNet,
                    'profit' => $figures->profit,
                    'receivables' => $figures->receivablesOpen,
                    'openUrl' => Dashboard::getUrl(panel: 'workspace', tenant: $business),
                    'billingUrl' => Billing::getUrl(['workspace' => $business->getKey()], panel: 'app'),
                ];
            })->all(),
            'setUpUrl' => SetUpBusiness::getUrl(panel: 'app'),
            'year' => BusinessMetrics::currentYear(),
        ];
    }
}
