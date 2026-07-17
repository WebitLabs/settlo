<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Headline recurring-revenue metrics for the platform.
 *
 * MRR is the sum of the monthly price of every paying subscription, where
 * "paying" means status Active — this includes subscriptions set to cancel at
 * period end, which keep their Active status (and keep paying) until the period
 * closes. Trialing subscriptions are excluded because they generate no revenue
 * yet. The trial-to-paid conversion rate is computed from the columns available
 * on the subscription: of every subscription that ever started a trial
 * (trial_starts_at set), the share that is now Active.
 */
class MrrOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $mrr = (float) Subscription::query()
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum('plans.price_monthly');

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

        return [
            Stat::make('MRR', $money($mrr))
                ->description('Active subscriptions')
                ->color('success'),
            Stat::make('Paying customers', (string) $paying)
                ->description('On a paid plan')
                ->color('primary'),
            Stat::make('Active trials', (string) $trialing)
                ->description('Not yet converted')
                ->color('info'),
            Stat::make('Trial conversion', $conversion.'%')
                ->description($convertedFromTrial.' of '.$startedTrial.' trials converted')
                ->color($conversion > 0 ? 'success' : 'gray'),
        ];
    }
}
