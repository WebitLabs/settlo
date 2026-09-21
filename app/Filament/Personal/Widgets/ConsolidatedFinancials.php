<?php

namespace App\Filament\Personal\Widgets;

use App\Models\User;
use App\Services\Reporting\BusinessMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Year-to-date figures summed over all of the owner's businesses.
 */
class ConsolidatedFinancials extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $user->isOwner() && $user->ownedEntities()->exists();
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        /** @var User $user */
        $user = Filament::auth()->user();
        $metrics = app(BusinessMetrics::class);
        $total = $metrics->forUser($user);
        $count = $user->ownedEntities()->count();
        $across = $count === 1 ? 'Your business' : "Across {$count} businesses";
        $money = fn (string $value): string => 'CHF '.number_format((float) $value, 0, '.', "'");

        return [
            Stat::make('Revenue YTD (excl. VAT)', $money($total->revenueNet))
                ->description($across)
                ->color('success'),
            Stat::make('Deductible expenses YTD', $money($total->deductibleExpenses))
                ->description($across)
                ->color('gray'),
            Stat::make('Profit YTD', $money($total->profit))
                ->description($across)
                ->color('primary'),
            Stat::make('Open receivables', $money($total->receivablesOpen))
                ->description($across)
                ->color((float) $total->receivablesOverdue > 0 ? 'danger' : 'gray'),
        ];
    }
}
