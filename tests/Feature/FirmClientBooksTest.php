<?php

use App\Filament\Firm\Resources\ClientEntities\ClientEntityResource;
use App\Filament\Firm\Resources\ClientEntities\Pages\ListClientEntities;
use App\Filament\Firm\Resources\ClientEntities\Pages\ViewClientEntity;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TaxEstimation;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

function makeFirmMember(AccountingFirm $firm, User $accountant): void
{
    AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $accountant->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);
}

function assignEntity(AccountingFirm $firm, BusinessEntity $entity, User $accountant, ?Carbon $revokedAt = null): AccountantAssignment
{
    return AccountantAssignment::create([
        'accounting_firm_id' => $firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => $accountant->getKey(),
        'assigned_at' => now()->subDay(),
        'revoked_at' => $revokedAt,
    ]);
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->firm = AccountingFirm::factory()->create();
    $this->accountant = User::factory()->accountant()->create();
    makeFirmMember($this->firm, $this->accountant);

    $this->owner = User::factory()->owner()->create();
    $this->assignedEntity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
    assignEntity($this->firm, $this->assignedEntity, $this->accountant);
});

function actAsFirmAccountant(): void
{
    test()->actingAs(test()->accountant);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant(test()->firm);
}

it('lists only entities actively assigned to the current firm', function () {
    $unassigned = BusinessEntity::factory()->forCanton('BE')->create();

    $revokedEntity = BusinessEntity::factory()->forCanton('LU')->create();
    assignEntity($this->firm, $revokedEntity, $this->accountant, revokedAt: now());

    $otherFirm = AccountingFirm::factory()->create();
    $otherFirmEntity = BusinessEntity::factory()->forCanton('GE')->create();
    assignEntity($otherFirm, $otherFirmEntity, $this->accountant);

    actAsFirmAccountant();

    Livewire::test(ListClientEntities::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->assignedEntity])
        ->assertCanNotSeeTableRecords([$unassigned, $revokedEntity, $otherFirmEntity]);
});

it('exposes the client list read-only with no create surface', function () {
    actAsFirmAccountant();

    expect(ClientEntityResource::canCreate())->toBeFalse();

    Livewire::test(ListClientEntities::class)
        ->assertOk()
        ->assertActionDoesNotExist('create');
});

it('renders owner books on the view page without mutating actions', function () {
    Invoice::factory()->for($this->assignedEntity, 'businessEntity')->create([
        'total' => 4200,
        'issue_date' => now(),
    ]);

    actAsFirmAccountant();

    Livewire::test(ViewClientEntity::class, [
        'record' => $this->assignedEntity->getKey(),
    ])
        ->assertOk()
        ->assertSee($this->assignedEntity->name)
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');
});

it('allows an assigned firm accountant to read a client\'s books via policy', function () {
    $invoice = (new Invoice)->forceFill(['business_entity_id' => $this->assignedEntity->getKey()]);
    $expense = (new Expense)->forceFill(['business_entity_id' => $this->assignedEntity->getKey()]);
    $tax = (new TaxEstimation)->forceFill(['business_entity_id' => $this->assignedEntity->getKey()]);

    expect($this->accountant->can('view', $invoice))->toBeTrue()
        ->and($this->accountant->can('view', $expense))->toBeTrue()
        ->and($this->accountant->can('view', $tax))->toBeTrue()
        ->and($this->accountant->can('viewAny', Invoice::class))->toBeTrue()
        ->and($this->accountant->can('viewAny', Expense::class))->toBeTrue()
        ->and($this->accountant->can('viewAny', TaxEstimation::class))->toBeTrue();
});

it('never grants an accountant write access to client books', function () {
    $invoice = (new Invoice)->forceFill(['business_entity_id' => $this->assignedEntity->getKey()]);
    $expense = (new Expense)->forceFill(['business_entity_id' => $this->assignedEntity->getKey()]);

    expect($this->accountant->can('create', Invoice::class))->toBeFalse()
        ->and($this->accountant->can('update', $invoice))->toBeFalse()
        ->and($this->accountant->can('delete', $invoice))->toBeFalse()
        ->and($this->accountant->can('create', Expense::class))->toBeFalse()
        ->and($this->accountant->can('update', $expense))->toBeFalse()
        ->and($this->accountant->can('delete', $expense))->toBeFalse();
});

it('denies reads when the assignment is revoked', function () {
    AccountantAssignment::query()
        ->where('business_entity_id', $this->assignedEntity->getKey())
        ->update(['revoked_at' => now()]);

    $invoice = (new Invoice)->forceFill(['business_entity_id' => $this->assignedEntity->getKey()]);
    $tax = (new TaxEstimation)->forceFill(['business_entity_id' => $this->assignedEntity->getKey()]);

    expect($this->accountant->can('view', $invoice))->toBeFalse()
        ->and($this->accountant->can('view', $tax))->toBeFalse();
});

it('denies reads across a firm boundary', function () {
    $otherFirm = AccountingFirm::factory()->create();
    $intruder = User::factory()->accountant()->create();
    makeFirmMember($otherFirm, $intruder);

    // Same entity, but only the original firm holds the active assignment.
    $invoice = (new Invoice)->forceFill(['business_entity_id' => $this->assignedEntity->getKey()]);

    expect($intruder->can('view', $invoice))->toBeFalse();
});

it('denies reads to an accountant who is not a member of the assigned firm', function () {
    // An assignment referencing the accountant but no firm membership record.
    $loneFirm = AccountingFirm::factory()->create();
    $entity = BusinessEntity::factory()->forCanton('ZG')->create();
    $nonMember = User::factory()->accountant()->create();
    assignEntity($loneFirm, $entity, $nonMember);

    $invoice = (new Invoice)->forceFill(['business_entity_id' => $entity->getKey()]);

    expect($nonMember->can('view', $invoice))->toBeFalse();
});
