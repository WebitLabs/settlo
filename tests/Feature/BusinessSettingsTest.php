<?php

use App\Enums\VatStatus;
use App\Filament\App\Pages\BusinessSettings;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\TaxProfile;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
    TaxProfile::factory()->for($this->entity, 'businessEntity')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->entity);
});

it('saves the business profile, invoicing defaults and tax profile', function () {
    Queue::fake();
    $canton = Canton::where('code', 'ZG')->firstOrFail();

    Livewire::test(BusinessSettings::class)
        ->fillForm([
            'name' => 'Renamed Consulting',
            'iban' => 'CH93 0076 2011 6238 5295 7',
            'invoice_number_prefix' => 'RC-',
            'tax_canton_id' => $canton->getKey(),
            'vat_status' => VatStatus::RegisteredVoluntary->value,
            'pillar3a_amount' => 50000, // over the cap
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $this->entity->refresh();
    expect($this->entity->name)->toBe('Renamed Consulting')
        ->and($this->entity->iban)->toBe('CH9300762011623852957')
        ->and($this->entity->invoice_number_prefix)->toBe('RC-');

    $taxProfile = $this->entity->taxProfile()->firstOrFail();
    expect($taxProfile->canton_id)->toBe($canton->getKey())
        ->and($taxProfile->vat_status)->toBe(VatStatus::RegisteredVoluntary)
        ->and((float) $taxProfile->pillar3a_amount)->toBe(35280.0);

    Queue::assertPushed(RecalculateTaxEstimation::class);
});

it('blocks a user who does not own the active tenant', function () {
    $intruder = User::factory()->owner()->create();
    $this->actingAs($intruder);
    Filament::setTenant($this->entity);

    expect(BusinessSettings::canAccess())->toBeFalse();
});
