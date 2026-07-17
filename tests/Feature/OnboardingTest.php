<?php

use App\Enums\BusinessEntityType;
use App\Enums\ResidencePermit;
use App\Enums\SubscriptionStatus;
use App\Enums\VatStatus;
use App\Filament\App\Tenancy\RegisterBusinessEntity;
use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\Plan;
use App\Models\TaxProfile;
use App\Models\User;
use App\Rules\ValidIban;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('provisions the business, tax profile, bank account and trial in one submission', function () {
    $canton = Canton::where('code', 'ZH')->firstOrFail();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    Livewire::test(RegisterBusinessEntity::class)
        ->fillForm([
            'name' => 'Test Consulting',
            'legal_name' => 'Test Consulting Sole Proprietorship',
            'type' => BusinessEntityType::SoleProprietorship->value,
            'uid' => 'CHE-123.456.789',
            'street' => 'Bahnhofstrasse',
            'street_number' => '1',
            'postal_code' => '8001',
            'city' => 'Zürich',
            'canton_id' => $canton->getKey(),
            'iban' => 'CH93 0076 2011 6238 5295 7',
            'default_payment_term_days' => 30,
            'default_language' => 'en',
            'invoice_number_prefix' => 'INV-',
            'marital_status' => 'single',
            'number_of_children' => 0,
            'residence_permit' => ResidencePermit::SwissOrCPermit->value,
            'pillar3a_amount' => 50000, // over the CHF 35,280 cap
            'kirchensteuer' => false,
            'vat_status' => VatStatus::NotRegistered->value,
            'estimated_annual_revenue' => 120000,
            'other_income' => 0,
            'plan_id' => $pro->getKey(),
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $entity = BusinessEntity::where('owner_id', $this->owner->getKey())->firstOrFail();
    expect($entity->name)->toBe('Test Consulting')
        ->and($entity->owner_id)->toBe($this->owner->getKey())
        ->and($entity->iban)->toBe('CH9300762011623852957');

    $taxProfile = TaxProfile::where('business_entity_id', $entity->getKey())->firstOrFail();
    expect($taxProfile->canton_id)->toBe($entity->canton_id)
        ->and((float) $taxProfile->pillar3a_amount)->toBe(35280.0); // silently capped

    $bankAccount = BankAccount::where('business_entity_id', $entity->getKey())->firstOrFail();
    expect($bankAccount->is_default)->toBeTrue()
        ->and($bankAccount->iban)->toBe('CH9300762011623852957');

    $this->owner->refresh();
    expect($this->owner->onboarding_completed_at)->not->toBeNull();

    $subscription = $this->owner->subscription()->firstOrFail();
    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->plan_id)->toBe($pro->getKey());
});

it('rejects an invalid IBAN in the banking step', function () {
    $canton = Canton::where('code', 'ZH')->firstOrFail();
    $pro = Plan::where('code', 'pro')->firstOrFail();

    Livewire::test(RegisterBusinessEntity::class)
        ->fillForm([
            'name' => 'Bad Iban Co',
            'type' => BusinessEntityType::SoleProprietorship->value,
            'canton_id' => $canton->getKey(),
            'iban' => 'CH93 0076 2011 6238 5295 8', // broken checksum
            'default_payment_term_days' => 30,
            'default_language' => 'en',
            'invoice_number_prefix' => 'INV-',
            'marital_status' => 'single',
            'residence_permit' => ResidencePermit::SwissOrCPermit->value,
            'vat_status' => VatStatus::NotRegistered->value,
            'plan_id' => $pro->getKey(),
        ])
        ->call('register')
        ->assertHasFormErrors(['iban']);

    expect(BusinessEntity::where('owner_id', $this->owner->getKey())->exists())->toBeFalse();
});

it('validates Swiss and Liechtenstein IBANs and rejects bad checksums', function (string $iban, bool $valid) {
    expect(ValidIban::isValid($iban))->toBe($valid);
})->with([
    'valid CH with spaces' => ['CH93 0076 2011 6238 5295 7', true],
    'valid CH normalized' => ['CH9300762011623852957', true],
    'valid LI' => ['LI44 0881 0000 2324 0130 0', true],
    'invalid CH checksum' => ['CH93 0076 2011 6238 5295 8', false],
    'wrong length' => ['CH9300762011623852', false],
    'non CH/LI country' => ['DE89 3704 0044 0532 0130 00', false],
    'garbage' => ['not-an-iban', false],
]);
