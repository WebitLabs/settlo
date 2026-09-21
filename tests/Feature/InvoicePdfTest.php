<?php

use App\Enums\InvoiceStatus;
use App\Enums\VatStatus;
use App\Filament\Workspace\Resources\Invoices\Pages\ViewInvoice;
use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Invoicing\InvoiceCreditor;
use App\Services\Invoicing\InvoicePdfService;
use App\Services\Invoicing\InvoiceService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** A valid Swiss QR-IBAN (IID 31999), so a sent invoice carries a 27-digit QRR. */
const PDF_QR_IBAN = 'CH4431999123000889012';

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    Queue::fake();

    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->vatRegistered()->for($this->owner, 'owner')->create([
        'iban' => PDF_QR_IBAN,
        'name' => 'Anna Muster',
        'legal_name' => 'Muster Design Studio',
        'street' => 'Bahnhofstrasse',
        'street_number' => '1',
        'postal_code' => '8001',
        'city' => 'Zürich',
        'uid' => 'CHE-105.829.940',
        'mwst_number' => 'CHE-105.829.940 MWST',
    ]);
    $this->client = Client::factory()->for($this->entity, 'businessEntity')->create();
    Subscription::factory()->forEntity($this->entity)->create(); // trialing → can write

    actAsWorkspace($this->owner, $this->entity);
});

/**
 * A draft with one taxable line, ready to be sent.
 */
function pdfInvoice(string $number = 'INV-2026-0001'): Invoice
{
    $invoice = Invoice::factory()->draft()->for(test()->entity, 'businessEntity')->create([
        'invoice_number' => $number,
        'client_id' => test()->client->getKey(),
    ]);
    InvoiceLineItem::factory()->for($invoice)->create(['quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 8.1]);

    return $invoice->refresh();
}

it('freezes the creditor identity when the invoice is issued (H4)', function () {
    $invoice = pdfInvoice();

    app(InvoiceService::class)->send($invoice);
    $invoice->refresh();

    expect($invoice->creditor_name)->toBe('Anna Muster')
        ->and($invoice->creditor_legal_name)->toBe('Muster Design Studio')
        ->and($invoice->creditor_street)->toBe('Bahnhofstrasse 1')
        ->and($invoice->creditor_postal)->toBe('8001')
        ->and($invoice->creditor_city)->toBe('Zürich')
        ->and($invoice->creditor_uid)->toBe('CHE-105.829.940')
        ->and($invoice->creditor_vat_number)->toBe('CHE-105.829.940 MWST')
        ->and($invoice->creditor_vat_registered)->toBeTrue();
});

it('renders the frozen creditor snapshot on an issued invoice, not the live business (H4)', function () {
    $invoice = pdfInvoice();
    app(InvoiceService::class)->send($invoice);

    // The business later renames itself, moves and leaves the VAT register.
    $this->entity->forceFill([
        'name' => 'Renamed GmbH',
        'legal_name' => 'Renamed Holding GmbH',
        'street' => 'Neugasse',
        'street_number' => '9',
        'postal_code' => '3000',
        'city' => 'Bern',
        'uid' => 'CHE-999.999.996',
        'mwst_number' => null,
        'vat_status' => VatStatus::NotRegistered,
    ])->save();

    $creditor = InvoiceCreditor::for($invoice->refresh()->load('businessEntity'));

    expect($creditor->frozen)->toBeTrue()
        ->and($creditor->name)->toBe('Anna Muster')
        ->and($creditor->legalName)->toBe('Muster Design Studio')
        ->and($creditor->addressLines())->toBe('Bahnhofstrasse 1, 8001 Zürich')
        ->and($creditor->uid)->toBe('CHE-105.829.940')
        ->and($creditor->vatRegistered)->toBeTrue()
        ->and($creditor->name)->not->toBe('Renamed GmbH');
});

it('falls back to the live business only for a draft preview (H4)', function () {
    $invoice = pdfInvoice();

    $creditor = InvoiceCreditor::for($invoice->load('businessEntity'));

    expect($creditor->frozen)->toBeFalse()
        ->and($creditor->name)->toBe('Anna Muster')
        ->and($creditor->uid)->toBe('CHE-105.829.940');

    // Editing the business is reflected immediately, because nothing is frozen yet.
    $this->entity->forceFill(['name' => 'Still Editable GmbH'])->save();

    expect(InvoiceCreditor::for($invoice->fresh()->load('businessEntity'))->name)->toBe('Still Editable GmbH');
});

it('previews a draft as a watermarked PDF before it is sent', function () {
    $invoice = pdfInvoice();
    $service = app(InvoicePdfService::class);

    expect($service->filename($invoice))->toBe('INV-2026-0001-draft.pdf')
        ->and(substr($service->render($invoice)->output(), 0, 4))->toBe('%PDF');

    app(InvoiceService::class)->send($invoice);

    expect($service->filename($invoice->refresh()))->toBe('INV-2026-0001.pdf');
});

it('offers the PDF action on drafts and authorizes it like the other invoice actions', function () {
    $invoice = pdfInvoice();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->assertActionVisible('pdf')
        ->assertActionEnabled('pdf');

    // An unrelated owner cannot read the document.
    $intruder = User::factory()->owner()->create();
    Subscription::factory()->for($intruder, 'user')->create();

    expect($intruder->can('view', $invoice))->toBeFalse()
        ->and($this->owner->can('view', $invoice))->toBeTrue();
});

it('renders the uploaded business logo on the PDF', function () {
    Storage::fake('public');
    $path = UploadedFile::fake()->image('logo.png', 120, 60)->store('logos', 'public');
    $this->entity->forceFill(['logo_url' => $path])->save();

    $invoice = pdfInvoice();
    $html = app(InvoicePdfService::class)->render($invoice->refresh())->getDomPDF()->outputHtml();

    expect($html)->toContain('data:image/png;base64,');
});

it('takes the QR-bill creditor IBAN from the default bank account', function () {
    $invoice = pdfInvoice();

    $this->entity->bankAccounts()->create([
        'account_name' => 'Everyday',
        'bank_name' => 'PostFinance',
        'iban' => 'CH5604835012345678009',
        'is_default' => false,
    ]);
    $this->entity->bankAccounts()->create([
        'account_name' => 'Invoicing',
        'bank_name' => 'Raiffeisen',
        'iban' => PDF_QR_IBAN,
        'is_default' => true,
    ]);

    app(InvoiceService::class)->send($invoice);

    expect($invoice->refresh()->creditor_iban)->toBe(PDF_QR_IBAN);
});

it('falls back to the invoicing IBAN when no bank account is the default', function () {
    $invoice = pdfInvoice();

    $this->entity->bankAccounts()->create([
        'account_name' => 'Everyday',
        'bank_name' => 'PostFinance',
        'iban' => 'CH5604835012345678009',
        'is_default' => false,
    ]);

    app(InvoiceService::class)->send($invoice);

    expect($invoice->refresh()->creditor_iban)->toBe(PDF_QR_IBAN);
});

it('refuses to send when neither a default account nor an IBAN is on file', function () {
    $this->entity->forceFill(['iban' => null])->save();
    $invoice = pdfInvoice();

    expect(fn () => app(InvoiceService::class)->send($invoice))
        ->toThrow(RuntimeException::class, 'needs an IBAN');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Draft);
});
