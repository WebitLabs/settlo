<?php

use App\Enums\InvoiceStatus;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->owner = User::factory()->owner()->create();
    Subscription::factory()->for($this->owner, 'user')->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')
        ->create(['iban' => 'CH4431999123000889012']);
    $this->client = Client::factory()->for($this->entity, 'businessEntity')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
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
