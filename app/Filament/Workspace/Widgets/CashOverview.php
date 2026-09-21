<?php

namespace App\Filament\Workspace\Widgets;

use App\Models\BusinessEntity;
use App\Services\Reporting\BusinessMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Cash position of the active business: payments received, open and overdue
 * receivables and, when VAT-registered, the VAT collected this year.
 */
class CashOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $entity = Filament::getTenant();

        if (! $entity instanceof BusinessEntity) {
            return [];
        }

        $year = BusinessMetrics::currentYear();
        $metrics = app(BusinessMetrics::class)->forEntity($entity, $year);
        $money = fn (string $value): string => 'CHF '.number_format((float) $value, 0, '.', "'");
        $hasOverdue = bccomp($metrics->receivablesOverdue, '0', 2) > 0;

        $stats = [
            Stat::make('Cash received YTD', $money($metrics->cashReceived))
                ->description('Payments recorded in '.$year)
                ->color('success'),
            Stat::make('Open receivables', $money($metrics->receivablesOpen))
                ->description('Sent, not yet paid')
                ->color('gray'),
            Stat::make('Overdue', $money($metrics->receivablesOverdue))
                ->description($hasOverdue ? 'Follow up with your clients' : 'Nothing overdue')
                ->color($hasOverdue ? 'danger' : 'gray'),
        ];

        if ($entity->isVatRegistered()) {
            $stats[] = Stat::make('VAT collected YTD', $money($metrics->vatCollected))
                ->description('To declare to the FTA')
                ->color('warning');
        }

        return $stats;
    }
}
