<?php

namespace App\Filament\App\Pages;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class VatSummary extends Page
{
    protected string $view = 'filament.app.pages.vat-summary';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Insights';

    protected static ?string $navigationLabel = 'VAT summary';

    protected static ?int $navigationSort = 4;

    public function getTitle(): string
    {
        return 'Input VAT summary';
    }

    /**
     * Whether the active business is VAT-registered (has an MWST number). Drives
     * the copy: reclaimable input tax vs. purely informational.
     */
    public function isRegistered(): bool
    {
        return filled(Filament::getTenant()?->mwst_number);
    }

    public function fiscalYear(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }

    /**
     * Reviewed expenses grouped by VAT rate for the current fiscal year, with
     * BCMath-summed gross, net and input-VAT columns. Highest rate first.
     *
     * @return list<array{rate: string, gross: string, net: string, input_vat: string}>
     */
    public function getVatRows(): array
    {
        $entity = Filament::getTenant();

        if ($entity === null) {
            return [];
        }

        $expenses = Expense::query()
            ->where('business_entity_id', $entity->getKey())
            ->where('status', ExpenseStatus::Reviewed->value)
            ->whereYear('expense_date', $this->fiscalYear())
            ->get(['vat_rate', 'amount', 'vat_amount', 'net_amount']);

        $groups = [];

        foreach ($expenses as $expense) {
            $key = rtrim(rtrim(number_format((float) $expense->vat_rate, 2, '.', ''), '0'), '.');
            $key = $key === '' ? '0' : $key;

            if (! isset($groups[$key])) {
                $groups[$key] = ['rate' => $key, 'gross' => '0.00', 'net' => '0.00', 'input_vat' => '0.00'];
            }

            $groups[$key]['gross'] = bcadd($groups[$key]['gross'], (string) $expense->amount, 2);
            $groups[$key]['net'] = bcadd($groups[$key]['net'], (string) $expense->net_amount, 2);
            $groups[$key]['input_vat'] = bcadd($groups[$key]['input_vat'], (string) $expense->vat_amount, 2);
        }

        krsort($groups, SORT_NUMERIC);

        return array_values($groups);
    }

    /**
     * Total potential input-tax deduction across every rate.
     */
    public function getTotalInputVat(): string
    {
        $total = '0.00';

        foreach ($this->getVatRows() as $row) {
            $total = bcadd($total, $row['input_vat'], 2);
        }

        return $total;
    }
}
