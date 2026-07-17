<?php

namespace App\Filament\App\Widgets;

use App\Enums\ExpenseStatus;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BusinessOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $entity = Filament::getTenant();
        $year = (int) config('settlo.current_fiscal_year', now()->year);

        $revenue = (float) $entity->invoices()
            ->countsAsRevenue()
            ->whereYear('issue_date', $year)
            ->sum('total');

        $deductible = (float) $entity->expenses()
            ->where('status', ExpenseStatus::Reviewed->value)
            ->whereYear('expense_date', $year)
            ->sum('deductible_amount');

        $estimation = $entity->latestTaxEstimation($year);

        $money = fn (float $value): string => 'CHF '.number_format($value, 0, '.', "'");

        return [
            Stat::make('Revenue YTD', $money($revenue))
                ->description($year.' invoiced')
                ->color('success'),
            Stat::make('Deductible expenses', $money($deductible))
                ->description('Confirmed this year')
                ->color('gray'),
            Stat::make('Estimated tax', $money((float) ($estimation?->total_tax_burden ?? 0)))
                ->description($estimation ? 'Updated '.$estimation->calculated_at->diffForHumans() : 'Not yet calculated')
                ->color('warning'),
            Stat::make('Monthly reserve', $money((float) ($estimation?->monthly_reserve ?? 0)))
                ->description('Set aside each month')
                ->color('primary'),
        ];
    }
}
