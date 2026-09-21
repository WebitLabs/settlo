<?php

namespace App\Filament\Workspace\Widgets;

use App\Enums\ExpenseStatus;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Invoice;
use App\Services\Reporting\BusinessMetrics;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Monthly revenue (excl. VAT), deductible expenses and profit of the active
 * business for the fiscal year. Grouped in PHP (small volumes, DB-agnostic).
 */
class ProfitChart extends ChartWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return 'Revenue & profit '.BusinessMetrics::currentYear();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array{revenue: list<float>, expenses: list<float>, profit: list<float>}
     */
    public function monthlyFigures(): array
    {
        $entity = Filament::getTenant();
        $revenue = array_fill(0, 12, 0.0);
        $expenses = array_fill(0, 12, 0.0);

        if ($entity instanceof BusinessEntity) {
            $year = BusinessMetrics::currentYear();
            $from = Carbon::create($year, 1, 1)->toDateString();
            $to = Carbon::create($year + 1, 1, 1)->toDateString();

            Invoice::query()
                ->where('business_entity_id', $entity->getKey())
                ->countsAsRevenue()
                ->where('issue_date', '>=', $from)
                ->where('issue_date', '<', $to)
                ->get(['issue_date', 'subtotal'])
                ->each(function (Invoice $invoice) use (&$revenue): void {
                    $revenue[$invoice->issue_date->month - 1] += (float) $invoice->subtotal;
                });

            Expense::query()
                ->where('business_entity_id', $entity->getKey())
                ->where('status', ExpenseStatus::Reviewed->value)
                ->where('expense_date', '>=', $from)
                ->where('expense_date', '<', $to)
                ->get(['expense_date', 'deductible_amount'])
                ->each(function (Expense $expense) use (&$expenses): void {
                    $expenses[$expense->expense_date->month - 1] += (float) $expense->deductible_amount;
                });
        }

        $round = fn (float $value): float => round($value, 2);

        return [
            'revenue' => array_map($round, $revenue),
            'expenses' => array_map($round, $expenses),
            'profit' => array_map(fn (float $income, float $cost): float => round($income - $cost, 2), $revenue, $expenses),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $figures = $this->monthlyFigures();

        return [
            'datasets' => [
                [
                    'type' => 'line',
                    'label' => 'Profit',
                    'data' => $figures['profit'],
                    'borderColor' => '#0F766E',
                    'backgroundColor' => '#0F766E',
                    'tension' => 0.3,
                    'order' => 0,
                ],
                [
                    'label' => 'Revenue (excl. VAT)',
                    'data' => $figures['revenue'],
                    'backgroundColor' => '#00A878',
                    'borderColor' => '#00A878',
                    'order' => 1,
                ],
                [
                    'label' => 'Deductible expenses',
                    'data' => $figures['expenses'],
                    'backgroundColor' => '#F59E0B',
                    'borderColor' => '#F59E0B',
                    'order' => 2,
                ],
            ],
            'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        ];
    }
}
