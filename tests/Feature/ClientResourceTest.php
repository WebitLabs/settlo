<?php

use App\Filament\App\Resources\Clients\Pages\CreateClient;
use App\Filament\App\Resources\Clients\Pages\ListClients;
use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CantonSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(CantonSeeder::class);

    $this->owner = User::factory()->owner()->create();
    Subscription::factory()->for($this->owner, 'user')->create();
    $this->entity = BusinessEntity::factory()->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
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
