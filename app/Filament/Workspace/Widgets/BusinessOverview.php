<?php

namespace App\Filament\Workspace\Widgets;

use App\Enums\PlanFeature;
use App\Filament\Personal\Pages\EditTaxProfile;
use App\Models\BusinessEntity;
use App\Services\Reporting\BusinessMetrics;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The headline year-to-date figures of the active business (BusinessMetrics)
 * and, with the tax engine, its share of the owner's estimated personal tax.
 */
class BusinessOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

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
        $money = fn (float|string|null $value): string => Money::rounded($value);

        $stats = [
            Stat::make('Revenue YTD', $money($metrics->revenueNet))
                ->description($year.' invoiced, excl. VAT')
                ->color('success'),
            // The deductible amount is what actually lowers the tax basis; the
            // gross spend is the sub-label so both numbers stay visible.
            Stat::make('Expenses YTD', $money($metrics->deductibleExpenses))
                ->description('Deductible of '.$money($metrics->expensesGross).' confirmed spend')
                ->color('gray'),
            Stat::make('Profit YTD', $money($metrics->profit))
                ->description(self::netMarginLabel($metrics->revenueNet, $metrics->profit))
                ->color((float) $metrics->profit < 0 ? 'danger' : 'primary'),
        ];

        if ($entity->hasFeature(PlanFeature::TaxEngine)) {
            $estimation = $entity->latestTaxEstimation($year);

            // The headline is what is owed on the year so far; the sub-label is
            // the amount to put aside every month, which is one twelfth of the
            // projected full year — not of the amount owed so far.
            $stat = Stat::make('Estimated tax (this business)', $money($estimation?->total_tax_burden))
                ->description($estimation
                    ? 'Set aside '.$money($estimation->projected_monthly_reserve).' / month'
                    : 'Add your tax profile to see this')
                ->color('warning');

            if ($estimation === null) {
                $stat->url(EditTaxProfile::getUrl(panel: 'app'));
            }

            $stats[] = $stat;
        }

        return $stats;
    }

    /**
     * "Revenue less deductible expenses", with the net margin once there is
     * revenue to divide by.
     */
    private static function netMarginLabel(string $revenueNet, string $profit): string
    {
        $label = 'Revenue less deductible expenses';

        if (bccomp($revenueNet, '0', 2) <= 0) {
            return $label;
        }

        $margin = round((float) $profit / (float) $revenueNet * 100, 1);

        return $label.' · '.Money::trimmed($margin, 1).'% net margin';
    }
}
