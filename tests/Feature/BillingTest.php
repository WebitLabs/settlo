<?php

use App\Billing\QuotaExceededException;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    $this->service = app(SubscriptionService::class);
    $this->solo = Plan::where('code', 'solo')->first();
    $this->pro = Plan::where('code', 'pro')->first();
    $this->confidence = Plan::where('code', 'confidence')->first();
});

it('starts a trial with the plan quota and no charge', function () {
    $user = User::factory()->owner()->create();

    $sub = $this->service->startTrial($user, $this->pro);

    expect($sub->status)->toBe(SubscriptionStatus::Trialing)
        ->and($sub->human_answers_quota)->toBe(1)
        ->and($sub->trial_ends_at->isFuture())->toBeTrue()
        ->and($sub->payments()->count())->toBe(0);
});

it('grants full Pro features during a Solo trial', function () {
    $user = User::factory()->owner()->create();
    $this->service->startTrial($user, $this->solo);

    expect($user->fresh()->hasFeature(PlanFeature::TaxEngine))->toBeTrue()
        ->and($user->fresh()->hasFeature(PlanFeature::AccountantAccess))->toBeTrue();
});

it('denies the tax engine to an active Solo plan but allows it on Pro', function () {
    $solo = User::factory()->owner()->create();
    Subscription::factory()->for($solo)->onPlan('solo', 0)->active()->create();

    $pro = User::factory()->owner()->create();
    Subscription::factory()->for($pro)->onPlan('pro', 1)->active()->create();

    expect($solo->fresh()->hasFeature(PlanFeature::TaxEngine))->toBeFalse()
        ->and($pro->fresh()->hasFeature(PlanFeature::TaxEngine))->toBeTrue();
});

it('upgrades immediately and records a payment', function () {
    $user = User::factory()->owner()->create();
    $sub = Subscription::factory()->for($user)->onPlan('solo', 0)->active()->create();

    $this->service->changePlan($sub, $this->pro);

    $sub->refresh();
    expect($sub->plan->code)->toBe('pro')
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->payments()->where('status', 'paid')->count())->toBe(1);
});

it('defers a downgrade to period end', function () {
    $user = User::factory()->owner()->create();
    $sub = Subscription::factory()->for($user)->onPlan('pro', 1)->active()->create();

    $this->service->changePlan($sub, $this->solo);

    $sub->refresh();
    expect($sub->plan->code)->toBe('pro')
        ->and($sub->pending_plan_id)->toBe($this->solo->id);

    $this->service->renew($sub);
    expect($sub->refresh()->plan->code)->toBe('solo');
});

it('meters human-answer quota and blocks the last-credit double spend', function () {
    $user = User::factory()->owner()->create();
    $sub = Subscription::factory()->for($user)->onPlan('pro', 1)->active()->create([
        'human_answers_quota' => 1,
        'human_answers_used' => 0,
    ]);

    $this->service->consumeHumanAnswer($sub);
    expect($sub->fresh()->human_answers_used)->toBe(1);

    $this->service->consumeHumanAnswer($sub);
})->throws(QuotaExceededException::class);

it('locks the account read-only once expired', function () {
    $user = User::factory()->owner()->create();
    $sub = Subscription::factory()->for($user)->expired()->create();

    expect($user->fresh()->canWrite())->toBeFalse();

    $this->service->expire($sub);
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Expired);
});
