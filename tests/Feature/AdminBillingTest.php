<?php

use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\AccountingFirms\Pages\CreateAccountingFirm;
use App\Filament\Admin\Resources\AccountingFirms\Pages\ViewAccountingFirm;
use App\Filament\Admin\Resources\AccountingFirms\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\Plans\Pages\EditPlan;
use App\Filament\Admin\Resources\SubscriptionPayments\Pages\ListSubscriptionPayments;
use App\Filament\Admin\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Firm\Resources\ClientEntities\ClientEntityResource;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->admin = User::factory()->superadmin()->create();
});

function actAsBillingSuperadmin(): void
{
    test()->actingAs(test()->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

it('persists a plan edit and writes an audit row', function () {
    $plan = Plan::where('code', 'pro')->firstOrFail();

    actAsBillingSuperadmin();

    Livewire::test(EditPlan::class, ['record' => $plan->getKey()])
        ->fillForm([
            'price_monthly' => 129,
            'human_answers_quota' => 42,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $plan->refresh();

    expect((float) $plan->price_monthly)->toBe(129.0)
        ->and($plan->human_answers_quota)->toBe(42);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'plan.updated',
        'subject_type' => $plan->getMorphClass(),
        'subject_id' => (string) $plan->getKey(),
    ]);
});

it('keeps the plan code immutable on edit', function () {
    $plan = Plan::where('code', 'pro')->firstOrFail();

    actAsBillingSuperadmin();

    Livewire::test(EditPlan::class, ['record' => $plan->getKey()])
        ->assertFormFieldIsDisabled('code');

    expect($plan->fresh()->code)->toBe('pro');
});

it('extends a trial and records the change', function () {
    $subscription = Subscription::factory()->create([
        'trial_ends_at' => now()->addDays(3),
    ]);
    $expected = $subscription->trial_ends_at->copy()->addDays(10);

    actAsBillingSuperadmin();

    Livewire::test(ListSubscriptions::class)
        ->callAction(TestAction::make('extendTrial')->table($subscription), ['days' => 10]);

    $subscription->refresh();

    expect($subscription->trial_ends_at->toDateString())->toBe($expected->toDateString())
        ->and($subscription->status)->toBe(SubscriptionStatus::Trialing);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'subscription.trial_extended',
        'subject_id' => (string) $subscription->getKey(),
    ]);
});

it('comps a subscription by extending the period and activating it', function () {
    $subscription = Subscription::factory()->create([
        'status' => SubscriptionStatus::Expired,
        'current_period_end' => now()->subDay(),
    ]);

    actAsBillingSuperadmin();

    Livewire::test(ListSubscriptions::class)
        ->callAction(TestAction::make('comp')->table($subscription), ['months' => 3]);

    $subscription->refresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->current_period_end->isFuture())->toBeTrue()
        ->and($subscription->current_period_end->greaterThan(now()->addMonthsNoOverflow(2)))->toBeTrue();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'subscription.comped',
        'subject_id' => (string) $subscription->getKey(),
    ]);
});

it('cancels a subscription through the billing service', function () {
    $subscription = Subscription::factory()->active()->create();

    actAsBillingSuperadmin();

    Livewire::test(ListSubscriptions::class)
        ->callAction(TestAction::make('cancel')->table($subscription));

    $subscription->refresh();

    expect($subscription->cancel_at_period_end)->toBeTrue()
        ->and($subscription->canceled_at)->not->toBeNull();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'subscription.cancelled',
        'subject_id' => (string) $subscription->getKey(),
    ]);
});

it('lists subscription payments read-only', function () {
    $subscription = Subscription::factory()->active()->create();
    $payment = SubscriptionPayment::create([
        'subscription_id' => $subscription->getKey(),
        'plan_id' => $subscription->plan_id,
        'amount' => 49,
        'currency_code' => 'CHF',
        'status' => 'paid',
        'gateway' => 'dummy',
        'gateway_reference' => 'ref_123',
        'paid_at' => now(),
        'period_start' => now(),
        'period_end' => now()->addMonth(),
    ]);

    actAsBillingSuperadmin();

    Livewire::test(ListSubscriptionPayments::class)
        ->assertCanSeeTableRecords([$payment]);
});

it('creates a firm with a first owner and audits it', function () {
    $accountant = User::factory()->accountant()->create();

    actAsBillingSuperadmin();

    Livewire::test(CreateAccountingFirm::class)
        ->fillForm([
            'name' => 'Alpine Treuhand AG',
            'email' => 'contact@alpine.test',
            'owner_email' => $accountant->email,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $firm = AccountingFirm::where('name', 'Alpine Treuhand AG')->firstOrFail();

    $this->assertDatabaseHas('accounting_firm_members', [
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $accountant->getKey(),
        'is_owner' => true,
    ]);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'firm.created',
        'subject_id' => $firm->getKey(),
    ]);
});

it('rejects a firm owner that is not an accountant', function () {
    $owner = User::factory()->owner()->create();

    actAsBillingSuperadmin();

    Livewire::test(CreateAccountingFirm::class)
        ->fillForm([
            'name' => 'Bad Firm',
            'owner_email' => $owner->email,
        ])
        ->call('create')
        ->assertHasFormErrors(['owner_email']);
});

it('revokes an assignment and cuts off the accountant, writing an audit row', function () {
    $firm = AccountingFirm::factory()->create();
    $accountant = User::factory()->accountant()->create();
    AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $accountant->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);

    $client = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->forCanton('ZH')->for($client, 'owner')->create();
    $assignment = AccountantAssignment::create([
        'accounting_firm_id' => $firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => $accountant->getKey(),
        'assigned_at' => now()->subDay(),
        'revoked_at' => null,
    ]);

    // The firm-panel surface that decides whether an accountant sees a client is
    // ClientEntityResource's tenant-scoped query — an active assignment grants it.
    $this->actingAs($accountant);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant($firm);
    expect(ClientEntityResource::getEloquentQuery()->whereKey($entity->getKey())->exists())->toBeTrue();

    actAsBillingSuperadmin();

    Livewire::test(AssignmentsRelationManager::class, [
        'ownerRecord' => $firm,
        'pageClass' => ViewAccountingFirm::class,
    ])
        ->callAction(TestAction::make('revoke')->table($assignment));

    $assignment->refresh();

    $this->actingAs($accountant);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant($firm);

    expect($assignment->revoked_at)->not->toBeNull()
        ->and(ClientEntityResource::getEloquentQuery()->whereKey($entity->getKey())->exists())->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'assignment.revoked',
        'subject_id' => $assignment->getKey(),
    ]);
});
