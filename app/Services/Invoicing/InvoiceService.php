<?php

namespace App\Services\Invoicing;

use App\Enums\InvoiceStatus;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Owns every server-authoritative mutation of an invoice: number assignment,
 * money totals (BCMath), and the status lifecycle. Amounts, status, the QR
 * reference and the creditor snapshot are written here via forceFill — they are
 * never mass-assignable, so a crafted form cannot forge them.
 */
class InvoiceService
{
    public function __construct(private readonly QrBillService $qrBill) {}

    /**
     * Next per-entity, per-year invoice number (e.g. INV-2026-0001), assigned
     * under a row lock so concurrent creates cannot collide.
     */
    public function nextInvoiceNumber(BusinessEntity $entity, ?int $year = null): string
    {
        $year ??= (int) config('settlo.current_fiscal_year', (int) date('Y'));
        $prefix = $entity->invoice_number_prefix ?: 'INV-';

        return DB::transaction(function () use ($entity, $year, $prefix): string {
            $last = Invoice::withTrashed()
                ->where('business_entity_id', $entity->getKey())
                ->where('invoice_number', 'like', "{$prefix}{$year}-%")
                ->orderByDesc('invoice_number')
                ->lockForUpdate()
                ->value('invoice_number');

            $seq = 1;
            if ($last !== null) {
                $seq = ((int) substr((string) strrchr($last, '-'), 1)) + 1;
            }

            return sprintf('%s%d-%04d', $prefix, $year, $seq);
        });
    }

    /**
     * Recompute each line total and the invoice subtotal/VAT/total from the
     * persisted line items. Money is computed with BCMath and rounded to CHF 0.01.
     */
    public function recalculateTotals(Invoice $invoice): Invoice
    {
        $subtotal = '0';
        $vatTotal = '0';

        foreach ($invoice->lineItems()->get() as $line) {
            $net = $this->round(bcmul((string) $line->quantity, (string) $line->unit_price, 6));
            $vat = $this->round(bcdiv(bcmul($net, (string) $line->vat_rate, 6), '100', 6));

            if ((string) $line->line_total !== $net) {
                $line->forceFill(['line_total' => $net])->save();
            }

            $subtotal = bcadd($subtotal, $net, 6);
            $vatTotal = bcadd($vatTotal, $vat, 6);
        }

        $invoice->forceFill([
            'subtotal' => $this->round($subtotal),
            'vat_amount' => $this->round($vatTotal),
            'total' => $this->round(bcadd($subtotal, $vatTotal, 6)),
        ])->save();

        return $invoice;
    }

    /**
     * Group the invoice's line items by VAT rate, summing the net base and VAT
     * amount per group with BCMath (rounded to CHF 0.01). Keyed by the rate
     * string (e.g. "8.1"); rates are returned highest-first. Read-only — it
     * never mutates the invoice totals.
     *
     * @return array<string, array{rate: string, base: string, vat: string}>
     */
    public function vatBreakdown(Invoice $invoice): array
    {
        $groups = [];

        foreach ($invoice->lineItems()->get() as $line) {
            $rate = $this->normalizeRate((string) $line->vat_rate);
            $net = $this->round(bcmul((string) $line->quantity, (string) $line->unit_price, 6));
            $vat = $this->round(bcdiv(bcmul($net, (string) $line->vat_rate, 6), '100', 6));

            if (! isset($groups[$rate])) {
                $groups[$rate] = ['rate' => $rate, 'base' => '0.00', 'vat' => '0.00'];
            }

            $groups[$rate]['base'] = bcadd($groups[$rate]['base'], $net, 2);
            $groups[$rate]['vat'] = bcadd($groups[$rate]['vat'], $vat, 2);
        }

        krsort($groups, SORT_NUMERIC);

        return $groups;
    }

    /**
     * Issue a draft invoice: freeze the creditor snapshot, mint the QR reference,
     * and transition to Sent. Recomputes totals first so the sent amount is
     * authoritative. Triggers a tax recalculation (revenue changed).
     */
    public function send(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw new RuntimeException('Only draft invoices can be sent.');
        }

        $this->recalculateTotals($invoice);

        if ($invoice->lineItems()->count() === 0 || bccomp((string) $invoice->total, '0', 2) <= 0) {
            throw new RuntimeException('Cannot send an invoice with no billable line items.');
        }

        $entity = $invoice->businessEntity()->first();
        if ($entity === null || blank($entity->iban)) {
            throw new RuntimeException('The business needs an IBAN before an invoice can be sent.');
        }

        $reference = $this->qrBill->generateReference($invoice->invoice_number, $entity->iban);

        $now = Carbon::now();
        $invoice->forceFill([
            'status' => InvoiceStatus::Sent,
            'sent_at' => $now,
            'status_changed_at' => $now,
            'qr_reference' => $reference,
            'creditor_iban' => preg_replace('/\s+/', '', (string) $entity->iban),
            'creditor_name' => $entity->legal_name ?: $entity->name,
            'creditor_street' => trim("{$entity->street} {$entity->street_number}") ?: null,
            'creditor_city' => $entity->city,
            'creditor_postal' => $entity->postal_code,
            'creditor_country' => 'CH',
        ])->save();

        RecalculateTaxEstimation::dispatch($entity->getKey());

        return $invoice;
    }

    /**
     * Record full payment and transition to Paid. Idempotent guard: only a Sent
     * or Overdue invoice can be paid.
     */
    public function markPaid(Invoice $invoice, ?Carbon $paidAt = null, string $method = 'bank_transfer'): Invoice
    {
        if (! in_array($invoice->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true)) {
            throw new RuntimeException('Only sent or overdue invoices can be marked paid.');
        }

        $paidAt ??= Carbon::now();

        DB::transaction(function () use ($invoice, $paidAt, $method): void {
            $invoice->payments()->create([
                'amount' => $invoice->total,
                'currency_code' => $invoice->currency_code,
                'paid_at' => $paidAt->toDateString(),
                'method' => $method,
            ]);

            $invoice->forceFill([
                'status' => InvoiceStatus::Paid,
                'paid_amount' => $invoice->total,
                'paid_at' => $paidAt,
                'status_changed_at' => Carbon::now(),
            ])->save();
        });

        // Revenue is unchanged (Sent/Overdue already counted), so no recalc needed.
        return $invoice;
    }

    /**
     * Cancel a non-terminal invoice. Recalculates tax only if the cancelled
     * invoice had been counting toward revenue.
     */
    public function cancel(Invoice $invoice): Invoice
    {
        if (in_array($invoice->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true)) {
            throw new RuntimeException('Paid or cancelled invoices cannot be cancelled.');
        }

        $wasRevenue = $invoice->status->countsAsRevenue();

        $invoice->forceFill([
            'status' => InvoiceStatus::Cancelled,
            'status_changed_at' => Carbon::now(),
        ])->save();

        if ($wasRevenue) {
            RecalculateTaxEstimation::dispatch($invoice->business_entity_id);
        }

        return $invoice;
    }

    /**
     * Flip every past-due Sent invoice to Overdue. Revenue is unaffected (both
     * statuses count), so no tax recalculation is triggered. Returns the count.
     */
    public function markOverdue(): int
    {
        return Invoice::query()
            ->where('status', InvoiceStatus::Sent->value)
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', Carbon::now()->toDateString())
            ->update([
                'status' => InvoiceStatus::Overdue->value,
                'status_changed_at' => Carbon::now(),
            ]);
    }

    /**
     * Normalise a VAT rate to a compact display string for grouping keys
     * (e.g. "8.10" → "8.1", "0.00" → "0").
     */
    private function normalizeRate(string $rate): string
    {
        $trimmed = rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }

    /**
     * Round a BCMath decimal string to CHF 0.01, half away from zero.
     */
    private function round(string $value, int $scale = 2): string
    {
        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($value, $factor, 1);
        $adjust = str_starts_with($value, '-') ? '-0.5' : '0.5';

        return bcdiv(bcadd($shifted, $adjust, 0), $factor, $scale);
    }
}
