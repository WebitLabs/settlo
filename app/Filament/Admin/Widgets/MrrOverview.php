<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Support\SimulatedBilling;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * Headline recurring-revenue metrics for the platform.
 *
 * MRR is the sum of the monthly (discounted) unit price of every paying
 * workspace subscription — yearly subscriptions count with 1/12 — where
 * "paying" means status Active — this includes subscriptions set to cancel at
 * period end, which keep their Active status (and keep paying) until the period
 * closes. Trialing subscriptions are excluded because they generate no revenue
 * yet. The trial-to-paid conversion rate is computed from the columns available
 * on the subscription: of every subscription that ever started a trial
 * (trial_starts_at set), the share that is now Active.
 *
 * While the bound gateway does not charge cards, every money figure here is
 * labelled as simulated — an investor demo must never read a simulation as
 * collected revenue.
 */
class MrrOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getHeading(): ?string
    {
        return SimulatedBilling::isActive() ? 'Recurring revenue (simulated)' : null;
    }

    protected function getDescription(): ?string
    {
        return SimulatedBilling::isActive() ? SimulatedBilling::notice() : null;
    }

    protected function getStats(): array
    {
        $mrr = (float) Subscription::query()
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum(DB::raw(
                "case when subscriptions.billing_interval = 'year' "
                .'then coalesce(subscriptions.unit_price, plans.price_yearly, plans.price_monthly * 10) / 12 '
                .'else coalesce(subscriptions.unit_price, plans.price_monthly) end'
            ));

        $paying = Subscription::query()
            ->where('status', SubscriptionStatus::Active->value)
            ->count();

        $trialing = Subscription::query()
            ->where('status', SubscriptionStatus::Trialing->value)
            ->count();

        $startedTrial = Subscription::query()
            ->whereNotNull('trial_starts_at')
            ->count();

        $convertedFromTrial = Subscription::query()
            ->whereNotNull('trial_starts_at')
            ->where('status', SubscriptionStatus::Active->value)
            ->count();

        $conversion = $startedTrial > 0
            ? round($convertedFromTrial / $startedTrial * 100, 1)
            : 0.0;

        $money = fn (float $value): string => 'CHF '.number_format($value, 0, '.', "'");
        $simulated = SimulatedBilling::isActive();

        return [
            Stat::make(SimulatedBilling::label('MRR'), $money($mrr))
                ->description($simulated
                    ? 'Active subscriptions · no money was collected'
                    : 'Active subscriptions')
                ->color($simulated ? 'warning' : 'success'),
            Stat::make(SimulatedBilling::label('Paying customers'), (string) $paying)
                ->description($simulated ? 'On a plan, paid without a card' : 'On a paid plan')
                ->color($simulated ? 'warning' : 'primary'),
            Stat::make('Active trials', (string) $trialing)
                ->description('Not yet converted')
                ->color('info'),
            Stat::make('Trial conversion', $conversion.'%')
                ->description($convertedFromTrial.' of '.$startedTrial.' trials converted')
                ->color($conversion > 0 ? 'success' : 'gray'),
        ];
    }
}
