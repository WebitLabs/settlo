<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Filament\Widgets\ChartWidget;

/**
 * Distribution of active subscriptions across the plan catalogue. Aggregated in
 * SQL with a portable group-by/count so it holds on both SQLite and PostgreSQL.
 */
class PlanMixChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Active subscriptions by plan';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = Subscription::query()
            ->where('subscriptions.status', SubscriptionStatus::Active->value)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->selectRaw('plans.name as plan_name, count(*) as total')
            ->groupBy('plans.name')
            ->orderBy('plans.name')
            ->pluck('total', 'plan_name');

        $palette = ['#0D1F2D', '#00A878', '#F59E0B', '#E24B4A', '#6366F1', '#0EA5E9'];

        return [
            'datasets' => [
                [
                    'label' => 'Active subscriptions',
                    'data' => $rows->values()->all(),
                    'backgroundColor' => array_slice($palette, 0, max(1, $rows->count())),
                ],
            ],
            'labels' => $rows->keys()->all(),
        ];
    }
}
