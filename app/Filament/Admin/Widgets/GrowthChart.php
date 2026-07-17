<?php

namespace App\Filament\Admin\Widgets;

use App\Models\User;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * New user sign-ups per month over the trailing 12 months. Counts are run one
 * month at a time so the query stays portable across the SQLite test database
 * and the PostgreSQL production database (no date-formatting SQL functions).
 */
class GrowthChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'New users per month';

    protected int|string|array $columnSpan = 'full';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $labels = [];
        $counts = [];

        $cursor = Carbon::now()->startOfMonth()->subMonths(11);

        for ($month = 0; $month < 12; $month++) {
            $start = $cursor->copy();
            $end = $cursor->copy()->endOfMonth();

            $labels[] = $start->format('M Y');
            $counts[] = User::query()
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $cursor->addMonth();
        }

        return [
            'datasets' => [
                [
                    'label' => 'New users',
                    'data' => $counts,
                    'borderColor' => '#00A878',
                    'backgroundColor' => 'rgba(0, 168, 120, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
