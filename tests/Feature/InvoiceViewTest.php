<?php

use App\Enums\InvoiceStatus;
use App\Enums\VatStatus;
use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Filament\Workspace\Resources\Invoices\Pages\ViewInvoice;
use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\InvoicePayment;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Section;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->vatRegistered()->for($this->owner, 'owner')
        ->create(['iban' => 'CH4431999123000889012']);
    $this->client = Client::factory()->for($this->entity, 'businessEntity')->create();
    Subscription::factory()->forEntity($this->entity)->create(); // trialing → can write

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('workspace'));
    Filament::setTenant($this->entity);
});

it('renders the read-only view with line items and the VAT breakdown', function () {
    $invoice = Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'invoice_number' => 'INV-2026-0100',
        'client_id' => $this->client->id,
        'status' => InvoiceStatus::Sent,
    ]);
    InvoiceLineItem::factory()->for($invoice)->create([
        'description' => 'Brand design retainer',
        'quantity' => 2,
        'unit_price' => 500,
        'vat_rate' => 8.1,
    ]);

    $this->get(InvoiceResource::getUrl('view', ['record' => $invoice], tenant: $this->entity))
        ->assertSuccessful()
        ->assertSee('Brand design retainer')
        ->assertSee('VAT breakdown');
});

it('blocks viewing an invoice belonging to another tenant', function () {
    $otherOwner = User::factory()->owner()->create();
    $otherEntity = BusinessEntity::factory()->for($otherOwner, 'owner')->create();
    $foreignInvoice = Invoice::factory()->for($otherEntity, 'businessEntity')->create([
        'invoice_number' => 'INV-2026-0200',
    ]);

    $this->get(InvoiceResource::getUrl('view', ['record' => $foreignInvoice], tenant: $this->entity))
        ->assertNotFound();
});

function draftInvoiceWithLine(BusinessEntity $entity, Client $client): Invoice
{
    $invoice = Invoice::factory()->draft()->for($entity, 'businessEntity')->create([
        'invoice_number' => 'INV-2026-0300',
        'client_id' => $client->id,
    ]);
    InvoiceLineItem::factory()->for($invoice)->create(['quantity' => 1, 'unit_price' => 500, 'vat_rate' => 8.1]);

    return $invoice;
}

it('offers send and edit on a draft and sends it from the view page', function () {
    $invoice = draftInvoiceWithLine($this->entity, $this->client);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->assertSee('Send the invoice to generate the Swiss QR-bill PDF.')
        ->assertActionVisible('send')
        ->assertActionVisible(EditAction::class)
        ->assertActionHidden('markPaid')
        // A draft can be previewed as a PDF before the irreversible send.
        ->assertActionVisible('pdf')
        ->callAction('send')
        ->assertNotified('Invoice sent')
        ->assertActionHidden('send')
        ->assertActionVisible('markPaid');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Sent);
});

it('marks a sent invoice as paid from the view page', function () {
    $invoice = draftInvoiceWithLine($this->entity, $this->client);
    $invoice->forceFill(['status' => InvoiceStatus::Sent, 'total' => 540.50])->save();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->assertActionHidden('send')
        ->assertActionHidden(EditAction::class)
        ->assertActionVisible('pdf')
        ->assertActionVisible('markPaid')
        ->callAction('markPaid', data: ['paid_at' => now()->toDateString(), 'method' => 'bank_transfer'])
        ->assertHasNoActionErrors()
        ->assertNotified('Invoice marked as paid');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('only lets the owner send, mark paid or cancel an invoice', function () {
    $invoice = draftInvoiceWithLine($this->entity, $this->client);
    $intruder = User::factory()->owner()->create();
    Subscription::factory()->for($intruder, 'user')->create();

    expect($this->owner->can('send', $invoice))->toBeTrue()
        ->and($this->owner->can('cancel', $invoice))->toBeTrue()
        ->and($this->owner->can('markPaid', $invoice))->toBeFalse()
        ->and($intruder->can('send', $invoice))->toBeFalse()
        ->and($intruder->can('cancel', $invoice))->toBeFalse();

    $invoice->forceFill(['status' => InvoiceStatus::Sent])->save();

    expect($this->owner->can('markPaid', $invoice))->toBeTrue()
        ->and($this->owner->can('send', $invoice))->toBeFalse()
        ->and($intruder->can('markPaid', $invoice))->toBeFalse();
});

it('warns when an invoice carries VAT but the business is not VAT-registered', function () {
    $this->entity->forceFill(['vat_status' => VatStatus::NotRegistered, 'mwst_number' => null])->save();
    $invoice = Invoice::factory()->draft()->for($this->entity, 'businessEntity')->create([
        'invoice_number' => 'INV-2026-0110',
        'client_id' => $this->client->id,
        'vat_amount' => 8.10,
    ]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertSee('your business is not VAT-registered');

    $this->entity->forceFill(['mwst_number' => 'CHE-148.830.302 MWST'])->save();

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertDontSee('your business is not VAT-registered');
});

it('formats money on the view page like the invoice form (L6)', function () {
    $invoice = Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'client_id' => $this->client->id,
        'status' => InvoiceStatus::Sent,
        'subtotal' => '1000.00',
        'vat_amount' => '81.49',
        'total' => '1081.49',
    ]);
    InvoiceLineItem::factory()->for($invoice)->create([
        'quantity' => 2,
        'unit_price' => 500,
        'vat_rate' => 8.1,
    ]);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->assertSee("CHF 1'081.49")
        ->assertSee('CHF 500.00')
        ->assertSee("8.1% on CHF 1'000.00 → CHF 81.00")
        ->assertDontSee('CHF 1,081.49');
});

it('lets the timeline span the full width only when there are no payments (L6)', function (bool $withPayment, int|string $span) {
    $invoice = draftInvoiceWithLine($this->entity, $this->client);

    if ($withPayment) {
        InvoicePayment::create(['invoice_id' => $invoice->getKey(), 'amount' => 500, 'paid_at' => now()->toDateString()]);
    }

    $timeline = Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])
        ->instance()
        ->getSchema('infolist')
        ->getComponent(fn (mixed $component): bool => $component instanceof Section && $component->getHeading() === 'Timeline');

    expect($timeline)->toBeInstanceOf(Section::class)
        ->and($timeline->getColumnSpan('default'))->toBe($span);
})->with([
    'no payments' => [false, 'full'],
    'with a payment' => [true, 1],
]);
