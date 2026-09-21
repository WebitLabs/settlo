<?php

use App\Filament\Admin\Resources\BusinessEntities\BusinessEntityResource;
use App\Filament\Admin\Resources\BusinessEntities\Pages\ListBusinessEntities;
use App\Filament\Admin\Resources\BusinessEntities\Pages\ViewBusinessEntity;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\CantonFiscalConfig;
use App\Models\Commune;
use App\Models\FederalTaxBracket;
use App\Models\FirmClientInvitation;
use App\Models\KnowledgeBaseEntry;
use App\Models\Plan;
use App\Models\SocialInsuranceRate;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\VatConfig;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->admin = User::factory()->superadmin()->create();
});

/*
|--------------------------------------------------------------------------
| H1 — the superadmin business-entity oversight screen
|--------------------------------------------------------------------------
*/

it('lets a superadmin open the admin business entities list and detail', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');

    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(BusinessEntityResource::canViewAny())->toBeTrue()
        ->and(BusinessEntityResource::canView($entity))->toBeTrue();

    Livewire::test(ListBusinessEntities::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$entity]);

    Livewire::test(ViewBusinessEntity::class, ['record' => $entity->getKey()])
        ->assertOk()
        ->assertSee($entity->name);

    $this->followingRedirects()
        ->get(BusinessEntityResource::getUrl('index', panel: 'admin'))
        ->assertOk();
});

it('keeps the admin business entity screen read-only', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');

    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    expect(BusinessEntityResource::canCreate())->toBeFalse()
        ->and(BusinessEntityResource::canEdit($entity))->toBeFalse()
        ->and(BusinessEntityResource::canDelete($entity))->toBeFalse();
});

it('does not widen the shared business entity policy for a superadmin', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');

    // The resource authorizes access itself (the admin panel is superadmin
    // only); the tenant policy must stay tenant-scoped.
    expect($this->admin->can('view', $entity))->toBeFalse()
        ->and($this->admin->can('update', $entity))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Default-deny coverage for models that previously had no policy at all
|--------------------------------------------------------------------------
*/

it('registers a policy for every panel-reachable model', function (string $model) {
    expect(Gate::getPolicyFor($model))->not->toBeNull();
})->with([
    Plan::class,
    Subscription::class,
    SubscriptionPayment::class,
    AccountingFirm::class,
    AccountingFirmMember::class,
    AccountantAssignment::class,
    FirmClientInvitation::class,
    KnowledgeBaseEntry::class,
    CantonFiscalConfig::class,
    FederalTaxBracket::class,
    SocialInsuranceRate::class,
    VatConfig::class,
    Canton::class,
    Commune::class,
]);

it('denies platform-owned records to an owner and allows them to a superadmin', function (string $model) {
    [$owner] = workspaceOwner('pro', 'ZH');

    expect($owner->can('viewAny', $model))->toBeFalse()
        ->and($owner->can('create', $model))->toBeFalse()
        ->and($this->admin->can('viewAny', $model))->toBeTrue();
})->with([
    Plan::class,
    KnowledgeBaseEntry::class,
    CantonFiscalConfig::class,
    FederalTaxBracket::class,
    SocialInsuranceRate::class,
    VatConfig::class,
    Canton::class,
    Commune::class,
]);

it('never allows a subscription or payment row to be written through a policy', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');
    $subscription = $entity->subscription;

    $payment = new SubscriptionPayment;
    $payment->forceFill([
        'subscription_id' => $subscription->getKey(),
        'amount' => 49,
        'currency_code' => 'CHF',
        'status' => 'paid',
        'paid_at' => now(),
    ])->save();

    expect($this->admin->can('update', $subscription))->toBeFalse()
        ->and($this->admin->can('delete', $subscription))->toBeFalse()
        ->and($this->admin->can('create', Subscription::class))->toBeFalse()
        ->and($this->admin->can('viewAny', Subscription::class))->toBeTrue()
        ->and($owner->can('view', $subscription))->toBeTrue()
        ->and($owner->can('viewAny', Subscription::class))->toBeFalse()
        ->and($this->admin->can('view', $payment))->toBeTrue()
        ->and($this->admin->can('update', $payment))->toBeFalse()
        ->and($this->admin->can('delete', $payment))->toBeFalse()
        ->and($owner->can('view', $payment))->toBeTrue()
        ->and($owner->can('viewAny', SubscriptionPayment::class))->toBeFalse();
});

it('scopes subscription reads to the owning workspace', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');
    [$stranger] = workspaceOwner('pro', 'BE');

    $subscription = $entity->subscription;

    expect($owner->can('view', $subscription))->toBeTrue()
        ->and($stranger->can('view', $subscription))->toBeFalse()
        ->and($this->admin->can('view', $subscription))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Firm-scoped models: members may read, only owners may write
|--------------------------------------------------------------------------
*/

it('scopes firm team and invitation abilities to membership and ownership', function () {
    $firm = AccountingFirm::factory()->create();
    $firmOwner = User::factory()->accountant()->create();
    $firmStaff = User::factory()->accountant()->create();
    $outsider = User::factory()->accountant()->create();

    $ownerMember = AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $firmOwner->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);
    $staffMember = AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $firmStaff->getKey(),
        'is_owner' => false,
        'joined_at' => now(),
    ]);

    $invitation = new FirmClientInvitation;
    $invitation->forceFill([
        'accounting_firm_id' => $firm->getKey(),
        'invited_by_id' => $firmOwner->getKey(),
        'email' => 'client@test.ch',
        'token_hash' => hash('sha256', 'token'),
        'expires_at' => now()->addDays(7),
    ])->save();

    expect($firmStaff->can('viewAny', AccountingFirmMember::class))->toBeTrue()
        ->and($firmStaff->can('view', $ownerMember))->toBeTrue()
        ->and($firmStaff->can('update', $ownerMember))->toBeFalse()
        ->and($firmStaff->can('delete', $ownerMember))->toBeFalse()
        ->and($firmOwner->can('update', $staffMember))->toBeTrue()
        ->and($firmOwner->can('delete', $staffMember))->toBeTrue()
        // A firm owner may never remove themselves.
        ->and($firmOwner->can('delete', $ownerMember))->toBeFalse()
        ->and($outsider->can('view', $ownerMember))->toBeFalse()
        ->and($firmStaff->can('view', $invitation))->toBeTrue()
        ->and($firmStaff->can('delete', $invitation))->toBeFalse()
        ->and($firmOwner->can('delete', $invitation))->toBeTrue()
        ->and($outsider->can('view', $invitation))->toBeFalse()
        ->and($this->admin->can('delete', $invitation))->toBeTrue();
});

it('never lets an assignment be created or deleted through a policy', function () {
    $firm = AccountingFirm::factory()->create();
    $accountant = User::factory()->accountant()->create();
    AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $accountant->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);

    [$owner, $entity] = workspaceOwner('pro', 'ZH');

    $assignment = AccountantAssignment::create([
        'accounting_firm_id' => $firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => null,
        'assigned_at' => now(),
    ]);

    expect($accountant->can('view', $assignment))->toBeTrue()
        ->and($accountant->can('delete', $assignment))->toBeFalse()
        ->and($this->admin->can('delete', $assignment))->toBeFalse()
        ->and($this->admin->can('create', AccountantAssignment::class))->toBeFalse()
        ->and($owner->can('view', $assignment))->toBeFalse();
});

it('lets only a firm owner change the firm profile', function () {
    $firm = AccountingFirm::factory()->create();
    $firmOwner = User::factory()->accountant()->create();
    $firmStaff = User::factory()->accountant()->create();

    AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $firmOwner->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);
    AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $firmStaff->getKey(),
        'is_owner' => false,
        'joined_at' => now(),
    ]);

    expect($firmOwner->can('update', $firm))->toBeTrue()
        ->and($firmStaff->can('update', $firm))->toBeFalse()
        ->and($firmStaff->can('view', $firm))->toBeTrue()
        ->and($firmStaff->can('delete', $firm))->toBeFalse()
        ->and($this->admin->can('update', $firm))->toBeTrue();
});

it('keeps an owner out of every business entity but their own', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');
    [$stranger, $otherEntity] = workspaceOwner('pro', 'BE');

    expect($owner->can('view', $entity))->toBeTrue()
        ->and($owner->can('view', $otherEntity))->toBeFalse()
        ->and($stranger->can('update', $entity))->toBeFalse()
        ->and(BusinessEntity::count())->toBe(2);
});
