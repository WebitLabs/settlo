<?php

use App\Enums\InvoiceStatus;
use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Filament\Workspace\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Workspace\Resources\Invoices\Pages\ListInvoices;
use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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

it('previews the same BCMath totals it saves and then opens the view page', function () {
    Repeater::fake();

    $component = Livewire::test(CreateInvoice::class)
        ->fillForm([
            'client_id' => $this->client->id,
            'language' => 'en',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-31',
            'lineItems' => [
                ['description' => 'Workshop', 'quantity' => 2, 'unit_price' => '100.00', 'vat_rate' => '8.1'],
                ['description' => 'Report', 'quantity' => 1, 'unit_price' => '800.45', 'vat_rate' => '8.1'],
            ],
        ])
        ->assertSee("CHF 1'081.49")
        ->assertSee("CHF 1'000.45")
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::firstOrFail();

    expect((string) $invoice->subtotal)->toBe('1000.45')
        ->and((string) $invoice->vat_amount)->toBe('81.04')
        ->and((string) $invoice->total)->toBe('1081.49');

    $component->assertRedirect(InvoiceResource::getUrl('view', ['record' => $invoice]));
});

it('validates line item quantities, prices and descriptions', function (array $line, string $field, string $rule) {
    Repeater::fake();

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'client_id' => $this->client->id,
            'language' => 'en',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-31',
            'lineItems' => [
                [...['description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => '8.1'], ...$line],
            ],
        ])
        ->call('create')
        ->assertHasFormErrors(["lineItems.0.{$field}" => $rule]);

    expect(Invoice::count())->toBe(0);
})->with([
    'zero quantity' => [['quantity' => 0], 'quantity', 'gt'],
    'negative price' => [['unit_price' => -1], 'unit_price', 'min'],
    'missing description' => [['description' => ''], 'description', 'required'],
]);

it('offers the VAT rate with an 8.1% default to a VAT-registered business', function () {
    Repeater::fake();

    Livewire::test(CreateInvoice::class)
        ->assertSchemaComponentExists('lineItems.0.vat_rate', checkComponentUsing: fn ($field): bool => $field instanceof Select && $field->getDefaultState() === '8.1')
        ->assertDontSee('this invoice is issued without VAT');
});

it('issues invoices without VAT when the business is not VAT-registered', function () {
    Repeater::fake();
    $entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['iban' => 'CH4431999123000889012']);
    Subscription::factory()->forEntity($entity)->create();
    $client = Client::factory()->for($entity, 'businessEntity')->create();
    Filament::setTenant($entity);

    Livewire::test(CreateInvoice::class)
        ->assertSee('Your business is not VAT-registered, so this invoice is issued without VAT.')
        ->assertSchemaComponentExists('lineItems.0.vat_rate', checkComponentUsing: fn ($field): bool => $field instanceof Hidden)
        ->fillForm([
            'client_id' => $client->id,
            'language' => 'en',
            'issue_date' => '2026-03-01',
            'due_date' => '2026-03-31',
            // A crafted payload still cannot store a VAT rate.
            'lineItems' => [
                ['description' => 'Consulting', 'quantity' => 2, 'unit_price' => 100, 'vat_rate' => '8.1'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $invoice = Invoice::where('business_entity_id', $entity->getKey())->firstOrFail();

    expect((float) $invoice->lineItems()->first()->vat_rate)->toBe(0.0)
        ->and((float) $invoice->vat_amount)->toBe(0.0)
        ->and((float) $invoice->total)->toBe(200.0);
});

it('derives the due date from the stored payment terms, not a fixed 30 days', function () {
    $this->entity->forceFill(['default_payment_term_days' => 10])->save();
    $slowPayer = Client::factory()->for($this->entity, 'businessEntity')->create([
        'name' => 'Slow Payer AG',
        'default_payment_term_days' => 60,
    ]);

    // With no client chosen yet, the business default applies.
    Livewire::test(CreateInvoice::class)
        ->assertFormSet(['due_date' => now()->addDays(10)->toDateString()])
        // Choosing a client applies that client's own term from its issue date.
        ->fillForm(['issue_date' => '2026-03-01'])
        ->fillForm(['client_id' => $slowPayer->getKey()])
        ->assertFormSet(['due_date' => '2026-04-30']);
});

it('keeps using the client payment term when the issue date moves', function () {
    $client = Client::factory()->for($this->entity, 'businessEntity')->create(['default_payment_term_days' => 14]);

    Livewire::test(CreateInvoice::class)
        ->fillForm(['client_id' => $client->getKey()])
        ->fillForm(['issue_date' => '2026-06-01'])
        ->assertFormSet(['due_date' => '2026-06-15']);
});
