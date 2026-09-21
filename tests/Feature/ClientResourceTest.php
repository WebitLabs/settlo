<?php

use App\Filament\Workspace\Resources\Clients\Pages\CreateClient;
use App\Filament\Workspace\Resources\Clients\Pages\EditClient;
use App\Filament\Workspace\Resources\Clients\Pages\ListClients;
use App\Filament\Workspace\Resources\Invoices\Pages\ListInvoices;
use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CantonSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(CantonSeeder::class);

    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->for($this->owner, 'owner')->create();
    Subscription::factory()->forEntity($this->entity)->create(); // trialing → can write

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('workspace'));
    Filament::setTenant($this->entity);
});

it('creates a client bound to the active tenant', function () {
    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Acme AG',
            'email' => 'acme@example.ch',
            'default_language' => 'en',
            'default_payment_term_days' => 30,
            'country_code' => 'CH',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::where('name', 'Acme AG')->first();

    expect($client)->not->toBeNull()
        ->and($client->business_entity_id)->toBe($this->entity->getKey());
});

it('lists only the active tenant\'s clients', function () {
    $mine = Client::factory()->for($this->entity, 'businessEntity')->create(['name' => 'My Client']);
    $theirs = Client::factory()->create(['name' => 'Other Tenant Client']);

    Livewire::test(ListClients::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('validates Swiss formats only for Swiss and Liechtenstein clients', function (array $data, string $field, bool $valid) {
    $component = Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Format Check GmbH',
            'default_language' => 'en',
            'default_payment_term_days' => 30,
            'country_code' => 'CH',
            ...$data,
        ])
        ->call('create');

    $valid
        ? $component->assertHasNoFormErrors()
        : $component->assertHasFormErrors([$field]);
})->with([
    'CH postal code with letters' => [['postal_code' => 'ZZ99'], 'postal_code', false],
    'CH postal code with 5 digits' => [['postal_code' => '80001'], 'postal_code', false],
    'CH postal code' => [['postal_code' => '8001'], 'postal_code', true],
    'LI postal code' => [['postal_code' => '9490', 'country_code' => 'LI'], 'postal_code', true],
    'DE postal code' => [['postal_code' => '10115', 'country_code' => 'de'], 'postal_code', true],
    'invalid CH VAT number' => [['vat_number' => 'INVALIDVAT'], 'vat_number', false],
    'CH VAT number with a bad check digit' => [['vat_number' => 'CHE-123.456.789 MWST'], 'vat_number', false],
    'CH VAT number' => [['vat_number' => 'CHE-105.829.940 MWST'], 'vat_number', true],
    'DE VAT number' => [['vat_number' => 'DE 123456789', 'country_code' => 'DE'], 'vat_number', true],
    'invalid DE VAT number' => [['vat_number' => '123', 'country_code' => 'DE'], 'vat_number', false],
    'country code too long' => [['country_code' => 'CHE'], 'country_code', false],
    'negative payment term' => [['default_payment_term_days' => -1], 'default_payment_term_days', false],
    'payment term over a year' => [['default_payment_term_days' => 400], 'default_payment_term_days', false],
]);

it('normalises VAT numbers and country codes', function () {
    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'Normalised AG',
            'default_language' => 'en',
            'default_payment_term_days' => 30,
            'country_code' => 'ch',
            'vat_number' => 'che105829940 mwst',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $client = Client::where('name', 'Normalised AG')->firstOrFail();

    expect($client->country_code)->toBe('CH')
        ->and($client->vat_number)->toBe('CHE-105.829.940 MWST');
});

it('warns how many invoices a client has before deleting it', function () {
    $client = Client::factory()->for($this->entity, 'businessEntity')->create();
    Invoice::factory()->count(2)->for($this->entity, 'businessEntity')->create(['client_id' => $client->getKey()]);
    $clientWithoutInvoices = Client::factory()->for($this->entity, 'businessEntity')->create();

    Livewire::test(ListClients::class)
        ->assertActionExists(
            TestAction::make(DeleteAction::class)->table($client),
            fn (DeleteAction $action): bool => str_contains((string) $action->getModalDescription(), 'This client has 2 invoices'),
        )
        ->assertActionExists(
            TestAction::make(DeleteAction::class)->table($clientWithoutInvoices),
            fn (DeleteAction $action): bool => str_contains((string) $action->getModalDescription(), 'moved to the trash'),
        )
        ->callAction(TestAction::make(DeleteAction::class)->table($client));

    expect($client->fresh()->trashed())->toBeTrue()
        ->and(Invoice::where('client_id', $client->getKey())->count())->toBe(2);
});

it('never permanently deletes a client that has invoices', function () {
    $client = Client::factory()->for($this->entity, 'businessEntity')->create();
    Invoice::factory()->for($this->entity, 'businessEntity')->create(['client_id' => $client->getKey()]);
    $client->delete();
    $clientWithoutInvoices = Client::factory()->for($this->entity, 'businessEntity')->create();
    $clientWithoutInvoices->delete();

    expect($this->owner->can('forceDelete', $client))->toBeFalse()
        ->and($this->owner->can('forceDelete', $clientWithoutInvoices))->toBeTrue();

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertActionHidden(ForceDeleteAction::class);

    Livewire::test(ListClients::class)
        ->filterTable('trashed', false)
        ->callTableBulkAction(ForceDeleteBulkAction::class, [$client, $clientWithoutInvoices]);

    expect(Client::withTrashed()->find($client->getKey()))->not->toBeNull()
        ->and(Client::withTrashed()->find($clientWithoutInvoices->getKey()))->toBeNull();
});

it('keeps showing a trashed client\'s name on its invoices', function () {
    $client = Client::factory()->for($this->entity, 'businessEntity')->create(['name' => 'Trashed Client AG']);
    $invoice = Invoice::factory()->for($this->entity, 'businessEntity')->create(['client_id' => $client->getKey()]);
    $client->delete();

    expect($invoice->fresh()->client?->name)->toBe('Trashed Client AG');

    Livewire::test(ListInvoices::class)
        ->assertSee('Trashed Client AG');
});

it('restores all rows when the table search is cleared', function () {
    $clients = Client::factory()->count(2)->for($this->entity, 'businessEntity')->create();

    Livewire::test(ListClients::class)
        ->searchTable('zzz-no-match')
        ->assertCanNotSeeTableRecords($clients)
        ->searchTable('')
        ->assertCanSeeTableRecords($clients);
});

it('refuses to save a client without a payment term or a language (H7)', function (array $data, string $field) {
    Livewire::test(CreateClient::class)
        ->fillForm([
            'name' => 'No Defaults AG',
            'country_code' => 'CH',
            'default_language' => 'en',
            'default_payment_term_days' => 30,
            ...$data,
        ])
        ->call('create')
        ->assertHasFormErrors([$field => 'required']);

    expect(Client::where('name', 'No Defaults AG')->exists())->toBeFalse();
})->with([
    'cleared payment term' => [['default_payment_term_days' => null], 'default_payment_term_days'],
    'empty payment term' => [['default_payment_term_days' => ''], 'default_payment_term_days'],
    'cleared language' => [['default_language' => null], 'default_language'],
    'empty language' => [['default_language' => ''], 'default_language'],
]);

it('refuses to clear the payment term or language on an existing client (H7)', function (string $field) {
    $client = Client::factory()->for($this->entity, 'businessEntity')->create([
        'default_payment_term_days' => 45,
        'default_language' => 'de',
    ]);

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->fillForm([$field => null])
        ->call('save')
        ->assertHasFormErrors([$field => 'required']);

    expect($client->refresh()->default_payment_term_days)->toBe(45)
        ->and($client->default_language)->toBe('de');
})->with(['default_payment_term_days', 'default_language']);
