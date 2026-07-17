<?php

use App\Enums\InvoiceStatus;
use App\Filament\App\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\App\Resources\Invoices\Pages\ListInvoices;
use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->owner = User::factory()->owner()->create();
    Subscription::factory()->for($this->owner, 'user')->create(); // trialing → can write
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')
        ->create(['iban' => 'CH4431999123000889012']);
    $this->client = Client::factory()->for($this->entity, 'businessEntity')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->entity);
});

it('creates a draft invoice with a generated number and BCMath totals', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'client_id' => $this->client->id,
            'language' => 'en',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-31',
            'reference' => 'PO-42',
            'lineItems' => [
                ['description' => 'Consulting', 'quantity' => 10, 'unit_price' => 150, 'vat_rate' => '8.1'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->business_entity_id)->toBe($this->entity->getKey())
        ->and($invoice->invoice_number)->toBe('INV-2026-0001')
        ->and($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and((float) $invoice->subtotal)->toBe(1500.00)
        ->and((float) $invoice->vat_amount)->toBe(121.50)
        ->and((float) $invoice->total)->toBe(1621.50);
});

it('sends a draft invoice through the table action', function () {
    $invoice = Invoice::factory()->draft()->for($this->entity, 'businessEntity')
        ->create(['invoice_number' => 'INV-2026-0002', 'client_id' => $this->client->id]);
    InvoiceLineItem::factory()->for($invoice)->create(['quantity' => 1, 'unit_price' => 500, 'vat_rate' => 8.1]);

    Livewire::test(ListInvoices::class)
        ->callAction(TestAction::make('send')->table($invoice));

    $invoice->refresh();

    expect($invoice->status)->toBe(InvoiceStatus::Sent)
        ->and($invoice->qr_reference)->not->toBeNull()
        ->and($invoice->creditor_iban)->toBe('CH4431999123000889012');
});

it('rejects an invoice referencing another tenant\'s client', function () {
    $otherOwner = User::factory()->owner()->create();
    $otherEntity = BusinessEntity::factory()->for($otherOwner, 'owner')->create();
    $foreignClient = Client::factory()->for($otherEntity, 'businessEntity')->create();

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'client_id' => $foreignClient->id,
            'language' => 'en',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-31',
            'lineItems' => [
                ['description' => 'Sneaky', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => '8.1'],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(['client_id']);

    expect(Invoice::count())->toBe(0);
});
