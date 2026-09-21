<?php

namespace App\Services\Reporting;

use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Models\BusinessEntity;
use App\Models\InvoiceLineItem;
use App\Services\Invoicing\InvoiceTotals;

/**
 * The numbers behind a Swiss VAT declaration (Form 300) for one business and
 * one reporting period:
 *
 * - output VAT: the VAT charged on invoices issued in the period (sent, paid
 *   or overdue — a draft was never issued and a cancelled one never counted),
 *   per rate, computed with the same BCMath code as the invoice totals;
 * - input VAT: the VAT paid on confirmed expenses dated in the period, per
 *   rate, as it was reviewed and confirmed by the user;
 * - net payable: output VAT − input VAT (negative means a refund).
 *
 * The rates are read from the data itself, never hard-coded, so a rate change
 * needs no code change here. Both sides use half-open date ranges, so the
 * date indexes are usable.
 */
class VatReturn
{
    public function forPeriod(BusinessEntity $entity, VatReturnPeriod $period): VatReturnResult
    {
        [$outputRows, $turnoverNet, $outputVat, $invoiceCount] = $this->outputSide($entity, $period);
        [$inputRows, $inputBase, $inputVat, $expenseCount] = $this->inputSide($entity, $period);

        return new VatReturnResult(
            period: $period,
            turnoverNet: $turnoverNet,
            outputVat: $outputVat,
            inputBase: $inputBase,
            inputVat: $inputVat,
            netPayable: bcsub($outputVat, $inputVat, 2),
            outputRows: $outputRows,
            inputRows: $inputRows,
            invoiceCount: $invoiceCount,
            expenseCount: $expenseCount,
        );
    }

    /**
     * Turnover and VAT charged, grouped per rate.
     *
     * @return array{0: list<array{rate: string, base: string, vat: string}>, 1: string, 2: string, 3: int}
     */
    private function outputSide(BusinessEntity $entity, VatReturnPeriod $period): array
    {
        $invoices = $entity->invoices()
            ->countsAsRevenue()
            ->where('issue_date', '>=', $period->start)
            ->where('issue_date', '<', $period->end)
            ->pluck('id');

        if ($invoices->isEmpty()) {
            return [[], '0.00', '0.00', 0];
        }

        $lines = InvoiceLineItem::query()
            ->whereIn('invoice_id', $invoices)
            ->get(['quantity', 'unit_price', 'vat_rate']);

        $totals = InvoiceTotals::forLines($lines);

        return [array_values($totals['breakdown']), $totals['subtotal'], $totals['vat'], $invoices->count()];
    }

    /**
     * Purchases and input tax paid, grouped per rate.
     *
     * @return array{0: list<array{rate: string, base: string, vat: string}>, 1: string, 2: string, 3: int}
     */
    private function inputSide(BusinessEntity $entity, VatReturnPeriod $period): array
    {
        $expenses = $entity->expenses()
            ->where('status', ExpenseStatus::Reviewed->value)
            ->where('expense_date', '>=', $period->start)
            ->where('expense_date', '<', $period->end)
            ->get(['vat_rate', 'net_amount', 'vat_amount']);

        $groups = [];
        $base = '0.00';
        $vat = '0.00';

        foreach ($expenses as $expense) {
            $rate = InvoiceTotals::normalizeRate($expense->vat_rate);

            $groups[$rate] ??= ['rate' => $rate, 'base' => '0.00', 'vat' => '0.00'];
            $groups[$rate]['base'] = bcadd($groups[$rate]['base'], InvoiceTotals::numeric($expense->net_amount), 2);
            $groups[$rate]['vat'] = bcadd($groups[$rate]['vat'], InvoiceTotals::numeric($expense->vat_amount), 2);

            $base = bcadd($base, InvoiceTotals::numeric($expense->net_amount), 2);
            $vat = bcadd($vat, InvoiceTotals::numeric($expense->vat_amount), 2);
        }

        krsort($groups, SORT_NUMERIC);

        return [array_values($groups), $base, $vat, $expenses->count()];
    }

    /**
     * The invoice statuses that count as issued turnover, for the copy on the
     * declaration page.
     *
     * @return list<string>
     */
    public static function issuedStatusLabels(): array
    {
        return array_map(
            fn (InvoiceStatus $status): string => $status->getLabel(),
            [InvoiceStatus::Sent, InvoiceStatus::Paid, InvoiceStatus::Overdue],
        );
    }
}
