<?php

namespace App\Services\Reporting;

use App\Models\BusinessEntity;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The year-end books of one business as plain CSV, ready to hand to a
 * Treuhänder or to import into accounting software: every invoice, every
 * expense, and the totals that tie them together.
 *
 * Amounts are written as raw decimals (1081.49) — machine-readable, not the
 * Swiss display format — and dates as ISO dates, so any tool reads them back
 * without guessing.
 */
class YearEndExportService
{
    public const string INVOICES = 'invoices';

    public const string EXPENSES = 'expenses';

    public const string SUMMARY = 'summary';

    /** @var list<string> */
    public const array DATASETS = [self::INVOICES, self::EXPENSES, self::SUMMARY];

    public function __construct(
        private readonly BusinessMetrics $metrics,
        private readonly VatReturn $vatReturn,
    ) {}

    /**
     * The header row of a dataset.
     *
     * @return list<string>
     */
    public function header(string $dataset): array
    {
        return match ($dataset) {
            self::INVOICES => [
                'invoice_number', 'issue_date', 'due_date', 'client', 'status', 'currency',
                'subtotal', 'vat_amount', 'total', 'paid_amount', 'paid_at',
            ],
            self::EXPENSES => [
                'expense_date', 'vendor', 'category', 'status', 'currency',
                'gross_amount', 'vat_rate', 'vat_amount', 'net_amount', 'deductible_pct', 'deductible_amount',
            ],
            self::SUMMARY => ['figure', 'amount', 'currency'],
            default => throw new InvalidArgumentException("Unknown export dataset [{$dataset}]."),
        };
    }

    /**
     * The rows of a dataset for one fiscal year.
     *
     * @return list<list<string>>
     */
    public function rows(BusinessEntity $entity, int $year, string $dataset): array
    {
        return match ($dataset) {
            self::INVOICES => $this->invoiceRows($entity, $year),
            self::EXPENSES => $this->expenseRows($entity, $year),
            self::SUMMARY => $this->summaryRows($entity, $year),
            default => throw new InvalidArgumentException("Unknown export dataset [{$dataset}]."),
        };
    }

    /**
     * The whole dataset as a CSV document (with a UTF-8 BOM, so Excel opens
     * Swiss names correctly).
     */
    public function csv(BusinessEntity $entity, int $year, string $dataset): string
    {
        $handle = fopen('php://temp', 'r+');

        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, $this->header($dataset), escape: '');

        foreach ($this->rows($entity, $year, $dataset) as $row) {
            fputcsv($handle, $row, escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    public function filename(BusinessEntity $entity, int $year, string $dataset): string
    {
        $slug = str($entity->name)->slug()->value() ?: 'business';

        return "{$slug}-{$year}-{$dataset}.csv";
    }

    public function download(BusinessEntity $entity, int $year, string $dataset): StreamedResponse
    {
        $csv = $this->csv($entity, $year, $dataset);

        return response()->streamDownload(
            fn () => print ($csv),
            $this->filename($entity, $year, $dataset),
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Every invoice issued in the year, drafts and cancelled ones included —
     * the export is the complete record of the year, not only what counted as
     * revenue.
     *
     * @return list<list<string>>
     */
    private function invoiceRows(BusinessEntity $entity, int $year): array
    {
        [$start, $end] = self::yearRange($year);

        return $entity->invoices()
            ->with('client')
            ->where('issue_date', '>=', $start)
            ->where('issue_date', '<', $end)
            ->orderBy('issue_date')
            ->orderBy('invoice_number')
            ->get()
            ->map(fn ($invoice): array => [
                (string) $invoice->invoice_number,
                (string) $invoice->issue_date?->toDateString(),
                (string) $invoice->due_date?->toDateString(),
                (string) $invoice->client?->name,
                $invoice->status->value,
                (string) $invoice->currency_code,
                (string) $invoice->subtotal,
                (string) $invoice->vat_amount,
                (string) $invoice->total,
                (string) $invoice->paid_amount,
                (string) $invoice->paid_at?->toDateString(),
            ])
            ->all();
    }

    /**
     * @return list<list<string>>
     */
    private function expenseRows(BusinessEntity $entity, int $year): array
    {
        [$start, $end] = self::yearRange($year);

        return $entity->expenses()
            ->with('category')
            ->where('expense_date', '>=', $start)
            ->where('expense_date', '<', $end)
            ->orderBy('expense_date')
            ->get()
            ->map(fn ($expense): array => [
                (string) $expense->expense_date?->toDateString(),
                (string) $expense->vendor,
                (string) $expense->category?->name_en,
                $expense->status->value,
                (string) $expense->currency_code,
                (string) $expense->amount,
                (string) $expense->vat_rate,
                (string) $expense->vat_amount,
                (string) $expense->net_amount,
                (string) $expense->deductible_pct,
                (string) $expense->deductible_amount,
            ])
            ->all();
    }

    /**
     * The totals that tie the two lists together, from the same services the
     * dashboards and the VAT declaration use — so the export can never tell a
     * different story than the app.
     *
     * @return list<list<string>>
     */
    private function summaryRows(BusinessEntity $entity, int $year): array
    {
        $metrics = $this->metrics->forEntity($entity, $year);
        $vat = $this->vatReturn->forPeriod($entity, VatReturnPeriod::make($year, 'year'));
        $currency = $entity->default_currency ?: 'CHF';

        $figures = [
            'Revenue (net of VAT)' => $metrics->revenueNet,
            'VAT collected' => $metrics->vatCollected,
            'Expenses (gross, confirmed)' => $metrics->expensesGross,
            'Deductible expenses' => $metrics->deductibleExpenses,
            'Profit (revenue less deductible expenses)' => $metrics->profit,
            'Cash received' => $metrics->cashReceived,
            'Open receivables' => $metrics->receivablesOpen,
            'Overdue receivables' => $metrics->receivablesOverdue,
            'Output VAT (invoiced)' => $vat->outputVat,
            'Input VAT (confirmed expenses)' => $vat->inputVat,
            'Net VAT payable' => $vat->netPayable,
        ];

        return array_map(
            fn (string $label, string $amount): array => [$label, $amount, $currency],
            array_keys($figures),
            array_values($figures),
        );
    }

    /**
     * The half-open [1 Jan, 1 Jan next year) range of a fiscal year.
     *
     * @return array{0: string, 1: string}
     */
    private static function yearRange(int $year): array
    {
        return [
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-01', $year + 1),
        ];
    }
}
