<?php

use App\Enums\SubscriptionStatus;
use App\Filament\Workspace\Resources\BankAccounts\BankAccountResource;
use App\Filament\Workspace\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\Workspace\Resources\BankAccounts\Pages\EditBankAccount;
use App\Filament\Workspace\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\Subscription;
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
    Subscription::factory()->forEntity($this->entity)->create(); // trialing → can write

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('workspace'));
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

it('accepts a Swiss IBAN with letters in the account part', function () {
    Livewire::test(CreateBankAccount::class)
        ->assertSeeHtml('aa99 9999 9*** **** **** *')
        ->fillForm([
            'account_name' => 'Business account',
            'bank_name' => 'Raiffeisen',
            'iban' => 'CH24 0076 2ABC 1234 5678 9',
            'currency_code' => 'CHF',
            'is_default' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(BankAccount::where('business_entity_id', $this->entity->getKey())->value('iban'))
        ->toBe('CH2400762ABC123456789');
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

it('never stores an invalid IBAN, even on repeated or "create another" submits (BUG-37)', function () {
    $data = [
        'account_name' => 'Bad account',
        'bank_name' => 'UBS',
        'iban' => 'CH12 3456 7890 1234 5678 9',
        'currency_code' => 'CHF',
    ];

    Livewire::test(CreateBankAccount::class)
        ->fillForm($data)
        ->call('create')
        ->assertHasFormErrors(['iban'])
        ->call('create')
        ->assertHasFormErrors(['iban'])
        ->call('createAnother')
        ->assertHasFormErrors(['iban']);

    expect(BankAccount::count())->toBe(0);
});

it('refuses to save an invalid IBAN at the model level', function () {
    makeBankAccount($this->entity, ['iban' => 'CH0000000000000000000']);
})->throws(InvalidArgumentException::class, 'A bank account needs a valid Swiss (CH) or Liechtenstein (LI) IBAN.');

it('normalizes the IBAN at the model level', function () {
    $account = makeBankAccount($this->entity, ['iban' => 'ch93 0076 2011 6238 5295 7']);

    expect($account->refresh()->iban)->toBe('CH9300762011623852957');
});

it('rejects an invalid IBAN on edit and leaves the account unchanged', function () {
    $account = makeBankAccount($this->entity, ['account_name' => 'Main']);

    Livewire::test(EditBankAccount::class, ['record' => $account->getKey()])
        ->fillForm(['iban' => 'CH00 0000 0000 0000 0000 0', 'account_name' => 'Renamed'])
        ->call('save')
        ->assertHasFormErrors(['iban']);

    $account->refresh();
    expect($account->iban)->toBe('CH9300762011623852957')
        ->and($account->account_name)->toBe('Main');
});

describe('read-only workspaces (M9)', function () {
    beforeEach(function () {
        $this->entity->subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
        $this->entity->unsetRelation('subscription');
    });

    it('lets the owner view but not change bank accounts of an expired workspace', function () {
        $account = makeBankAccount($this->entity);

        expect($this->owner->can('viewAny', BankAccount::class))->toBeTrue()
            ->and($this->owner->can('view', $account))->toBeTrue()
            ->and($this->owner->can('create', BankAccount::class))->toBeFalse()
            ->and($this->owner->can('update', $account))->toBeFalse()
            ->and($this->owner->can('delete', $account))->toBeFalse()
            ->and($this->owner->can('deleteAny', BankAccount::class))->toBeFalse();

        Livewire::test(ListBankAccounts::class)
            ->assertCanSeeTableRecords([$account])
            ->assertActionHidden('create');

        $this->get(BankAccountResource::getUrl('create', tenant: $this->entity))->assertForbidden();
        $this->get(BankAccountResource::getUrl('edit', ['record' => $account], tenant: $this->entity))->assertForbidden();
    });

    it('never lets an owner see or change another owner\'s bank account', function () {
        $otherOwner = User::factory()->owner()->create();
        $otherEntity = BusinessEntity::factory()->forCanton('ZH')->for($otherOwner, 'owner')->create();
        Subscription::factory()->forEntity($otherEntity)->active()->create();
        $theirs = makeBankAccount($otherEntity);

        expect($this->owner->can('view', $theirs))->toBeFalse()
            ->and($this->owner->can('update', $theirs))->toBeFalse()
            ->and($this->owner->can('delete', $theirs))->toBeFalse();
    });
});
