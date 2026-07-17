<?php

use App\Enums\InvoiceStatus;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Services\Invoicing\InvoicePdfService;
use App\Services\Invoicing\InvoiceService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Queue;

// A valid Swiss QR-IBAN (IID 31999) so sent invoices carry a 27-digit QRR.
const QR_IBAN = 'CH4431999123000889012';

beforeEach(function () {
    // Full reference data so the tax recalculation dispatched on send() (sync in
    // tests) can resolve rates end-to-end.
    $this->seed(ReferenceDataSeeder::class);
});

it('computes subtotal, VAT and total with BCMath from line items', function () {
    $invoice = Invoice::factory()->draft()->create();
    InvoiceLineItem::factory()->for($invoice)->create(['quantity' => 2, 'unit_price' => 100, 'vat_rate' => 8.1]);
    InvoiceLineItem::factory()->for($invoice)->create(['quantity' => 1, 'unit_price' => 50, 'vat_rate' => 2.6]);

    app(InvoiceService::class)->recalculateTotals($invoice);
    $invoice->refresh();

    // net 200 + 50 = 250; VAT 16.20 + 1.30 = 17.50; total 267.50
    expect((float) $invoice->subtotal)->toBe(250.00)
        ->and((float) $invoice->vat_amount)->toBe(17.50)
        ->and((float) $invoice->total)->toBe(267.50)
        ->and((float) $invoice->lineItems()->first()->line_total)->toBe(200.00);
});

it('assigns sequential per-entity invoice numbers under contention', function () {
    $entity = BusinessEntity::factory()->create();
    $service = app(InvoiceService::class);

    expect($service->nextInvoiceNumber($entity, 2026))->toBe('INV-2026-0001');

    Invoice::factory()->for($entity, 'businessEntity')->create(['invoice_number' => 'INV-2026-0001']);

    expect($service->nextInvoiceNumber($entity, 2026))->toBe('INV-2026-0002');
});

it('freezes the creditor snapshot and mints a 27-digit QRR when sending', function () {
    Queue::fake();
    $entity = BusinessEntity::factory()->forCanton('ZH')->create(['iban' => QR_IBAN]);
    $invoice = Invoice::factory()->draft()->for($entity, 'businessEntity')->create(['invoice_number' => 'INV-2026-0007']);
    InvoiceLineItem::factory()->for($invoice)->create(['quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 8.1]);

    app(InvoiceService::class)->send($invoice->refresh());
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->sent_at)->not->toBeNull()
        ->and($invoice->creditor_iban)->toBe(QR_IBAN)
        ->and(strlen((string) $invoice->qr_reference))->toBe(27);

    Queue::assertPushed(RecalculateTaxEstimation::class);
});

it('refuses to send an invoice with no billable lines', function () {
    $invoice = Invoice::factory()->draft()->create();

    expect(fn () => app(InvoiceService::class)->send($invoice))->toThrow(RuntimeException::class);
});

it('refuses to send an invoice twice', function () {
    $entity = BusinessEntity::factory()->create(['iban' => QR_IBAN]);
    $invoice = Invoice::factory()->draft()->for($entity, 'businessEntity')->create(['invoice_number' => 'INV-2026-0008']);
    InvoiceLineItem::factory()->for($invoice)->create(['quantity' => 1, 'unit_price' => 100, 'vat_rate' => 8.1]);
    $service = app(InvoiceService::class);
    $service->send($invoice->refresh());

    expect(fn () => $service->send($invoice->refresh()))->toThrow(RuntimeException::class);
});

it('marks a sent invoice paid and records a payment', function () {
    $invoice = Invoice::factory()->create(['status' => InvoiceStatus::Sent, 'total' => 500]);

    app(InvoiceService::class)->markPaid($invoice);
    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Paid)
        ->and((float) $invoice->paid_amount)->toBe(500.00)
        ->and($invoice->payments()->count())->toBe(1);
});

it('flips only past-due sent invoices to overdue', function () {
    Invoice::factory()->overdue()->create();
    Invoice::factory()->create(['status' => InvoiceStatus::Sent, 'due_date' => now()->addDays(10)]);

    $count = app(InvoiceService::class)->markOverdue();

    expect($count)->toBe(1);
});

it('renders a valid PDF document for a sent invoice', function () {
    $entity = BusinessEntity::factory()->create(['iban' => QR_IBAN]);
    $invoice = Invoice::factory()->draft()->for($entity, 'businessEntity')->create(['invoice_number' => 'INV-2026-0009']);
    InvoiceLineItem::factory()->for($invoice)->create(['quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 8.1]);
    app(InvoiceService::class)->send($invoice->refresh());

    $output = app(InvoicePdfService::class)->render($invoice->refresh())->output();

    expect(substr($output, 0, 4))->toBe('%PDF');
});
