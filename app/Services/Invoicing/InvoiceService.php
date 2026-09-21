<?php

namespace App\Services\Invoicing;

use App\Enums\InvoiceStatus;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use Illuminate\Database\QueryException;
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
    /** Attempts to win a unique invoice number when two creates race. */
    private const int NUMBER_ATTEMPTS = 5;

    public function __construct(private readonly QrBillService $qrBill) {}

    /**
     * Create a draft invoice for the given business. The number is minted and
     * the row inserted inside one transaction, so the lock taken while reading
     * the last number is still held when the insert lands; should two creates
     * still race (a gap the lock cannot cover), the unique violation is
     * retried with the next number instead of surfacing a 500.
     *
     * The tenant, number, currency and status are server-controlled and not
     * mass-assignable, so a crafted payload cannot forge them.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createDraft(BusinessEntity $entity, array $attributes): Invoice
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($entity, $attributes): Invoice {
                    $invoice = new Invoice;
                    $invoice->fill($attributes);
                    $invoice->forceFill([
                        'business_entity_id' => $entity->getKey(),
                        'currency_code' => $entity->default_currency ?: 'CHF',
                        'status' => InvoiceStatus::Draft->value,
                        'invoice_number' => $this->nextInvoiceNumber($entity),
                    ]);
                    $invoice->save();

                    return $invoice;
                });
            } catch (QueryException $e) {
                if ($attempt >= self::NUMBER_ATTEMPTS || ! $this->isDuplicateNumber($e)) {
                    throw $e;
                }
            }
        }
    }

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
        $lines = $invoice->lineItems()->get();

        foreach ($lines as $line) {
            $net = InvoiceTotals::lineNet($line->quantity, $line->unit_price);

            if ((string) $line->line_total !== $net) {
                $line->forceFill(['line_total' => $net])->save();
            }
        }

        $totals = InvoiceTotals::forLines($lines);

        $invoice->forceFill([
            'subtotal' => $totals['subtotal'],
            'vat_amount' => $totals['vat'],
            'total' => $totals['total'],
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
        return InvoiceTotals::forLines($invoice->lineItems()->get())['breakdown'];
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
        if ($entity === null) {
            throw new RuntimeException('The invoice is not attached to a business.');
        }

        $creditor = InvoiceCreditor::fromEntity($entity);

        if (blank($creditor->iban)) {
            throw new RuntimeException('The business needs an IBAN — add a default bank account or an IBAN in your invoicing settings — before an invoice can be sent.');
        }

        if (! $entity->isVatRegistered() && bccomp((string) $invoice->vat_amount, '0', 2) > 0) {
            throw new RuntimeException('Your business is not VAT-registered — remove VAT from the line items before sending.');
        }

        $reference = $this->qrBill->generateReference($invoice->invoice_number, $creditor->iban);

        $now = Carbon::now();
        $invoice->forceFill([
            'status' => InvoiceStatus::Sent,
            'sent_at' => $now,
            'status_changed_at' => $now,
            'qr_reference' => $reference,
            ...$creditor->toSnapshot(),
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
     * Flip every past-due Sent invoice to Overdue — an invoice due today is
     * not overdue yet (see {@see Invoice::scopeOverdue()}). Revenue is
     * unaffected (both statuses count), so no tax recalculation is triggered.
     * Returns the count.
     */
    public function markOverdue(): int
    {
        return Invoice::query()
            ->overdue()
            ->where('status', InvoiceStatus::Sent->value)
            ->update([
                'status' => InvoiceStatus::Overdue->value,
                'status_changed_at' => Carbon::now(),
            ]);
    }

    /**
     * Whether the failure is the per-business invoice number unique index
     * (PostgreSQL 23505 / MySQL 23000) rather than a real error.
     */
    private function isDuplicateNumber(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23505', '23000'], true);
    }
}
