<?php

use App\Filament\App\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\App\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * @param  array<string, mixed>  $attributes
 */
function makeBankAccount(BusinessEntity $entity, array $attributes = []): BankAccount
{
    $account = new BankAccount;
    $account->forceFill([
        'business_entity_id' => $entity->getKey(),
        'bank_name' => 'PostFinance',
        'account_name' => 'Account',
        'iban' => 'CH9300762011623852957',
        'currency_code' => 'CHF',
        'is_default' => false,
        ...$attributes,
    ])->save();

    return $account;
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->entity);
});

it('only lists bank accounts for the active tenant', function () {
    $mine = makeBankAccount($this->entity, ['account_name' => 'Mine']);

    $otherOwner = User::factory()->owner()->create();
    $otherEntity = BusinessEntity::factory()->forCanton('ZH')->for($otherOwner, 'owner')->create();
    $theirs = makeBankAccount($otherEntity, ['account_name' => 'Theirs']);

    Livewire::test(ListBankAccounts::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('creates a bank account scoped to the tenant with a normalized IBAN', function () {
    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'account_name' => 'Business account',
            'bank_name' => 'UBS',
            'iban' => 'CH93 0076 2011 6238 5295 7',
            'currency_code' => 'CHF',
            'is_default' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $account = BankAccount::where('business_entity_id', $this->entity->getKey())->firstOrFail();
    expect($account->iban)->toBe('CH9300762011623852957')
        ->and($account->is_default)->toBeTrue(); // first account is forced default
});

it('rejects an invalid IBAN', function () {
    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'account_name' => 'Bad account',
            'bank_name' => 'UBS',
            'iban' => 'CH00 0000 0000 0000 0000 0',
            'currency_code' => 'CHF',
        ])
        ->call('create')
        ->assertHasFormErrors(['iban']);
});

it('keeps a single default account per tenant', function () {
    $first = makeBankAccount($this->entity, ['account_name' => 'First', 'is_default' => true]);

    Livewire::test(CreateBankAccount::class)
        ->fillForm([
            'account_name' => 'Second',
            'bank_name' => 'ZKB',
            'iban' => 'CH47 0023 0000 1234 5678 9',
            'currency_code' => 'CHF',
            'is_default' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $first->refresh();
    $second = BankAccount::where('business_entity_id', $this->entity->getKey())
        ->where('account_name', 'Second')->firstOrFail();

    expect($first->is_default)->toBeFalse()
        ->and($second->is_default)->toBeTrue()
        ->and(BankAccount::where('business_entity_id', $this->entity->getKey())->where('is_default', true)->count())->toBe(1);
});
