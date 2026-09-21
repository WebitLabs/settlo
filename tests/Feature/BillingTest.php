<?php

use App\Billing\CheckoutUnavailableException;
use App\Billing\DummyGateway;
use App\Billing\PaymentGateway;
use App\Billing\QuotaExceededException;
use App\Billing\SimulatedGateway;
use App\Billing\StripeGateway;
use App\Enums\BillingInterval;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\StripeSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use App\Services\Billing\WorkspacePricing;
use Database\Seeders\CantonSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([CantonSeeder::class, PlanSeeder::class]);
    $this->service = app(SubscriptionService::class);
    $this->solo = Plan::where('code', 'solo')->first();
    $this->pro = Plan::where('code', 'pro')->first();
    $this->confidence = Plan::where('code', 'confidence')->first();
    $this->owner = User::factory()->owner()->create();
});

function newWorkspace(User $owner): BusinessEntity
{
    return BusinessEntity::factory()->for($owner, 'owner')->create();
}

it('binds the dummy gateway in tests and without Stripe keys', function () {
    expect(app(PaymentGateway::class))->toBeInstanceOf(DummyGateway::class);

    config(['settlo.payment_gateway' => 'stripe', 'cashier.secret' => null]);

    expect(app(PaymentGateway::class))->toBeInstanceOf(DummyGateway::class);
});

describe('gateway binding outside local and tests (H1)', function () {
    it('binds Stripe when it is selected and configured', function (string $environment) {
        app()->detectEnvironment(fn (): string => $environment);
        config(['settlo.payment_gateway' => 'stripe', 'cashier.secret' => 'sk_test_fake']);

        expect(app(PaymentGateway::class))->toBeInstanceOf(StripeGateway::class);
    })->with(['production', 'staging']);

    it('fails loudly instead of falling back to the dummy gateway', function (string $environment, string $gateway) {
        app()->detectEnvironment(fn (): string => $environment);
        config(['settlo.payment_gateway' => $gateway, 'cashier.secret' => null]);

        expect(DummyGateway::isAllowed())->toBeFalse()
            ->and(fn () => app(PaymentGateway::class))->toThrow(RuntimeException::class);
    })->with([
        'production, stripe without secret' => ['production', 'stripe'],
        'staging, stripe without secret' => ['staging', 'stripe'],
        'production, dummy' => ['production', 'dummy'],
    ]);

    it('binds the simulated gateway in every environment when it is selected explicitly', function (string $environment) {
        app()->detectEnvironment(fn (): string => $environment);
        config(['settlo.payment_gateway' => 'simulated', 'cashier.secret' => null]);

        $gateway = app(PaymentGateway::class);

        expect($gateway)->toBeInstanceOf(SimulatedGateway::class)
            ->and($gateway->name())->toBe('simulated')
            ->and($gateway->completesCheckoutInstantly())->toBeTrue();
    })->with(['production', 'staging', 'local', 'testing']);

    it('never completes a real checkout in-process', function () {
        expect(app(PaymentGateway::class))->toBeInstanceOf(DummyGateway::class)
            ->and(app(PaymentGateway::class)->completesCheckoutInstantly())->toBeFalse();

        config(['settlo.payment_gateway' => 'stripe', 'cashier.secret' => 'sk_test_fake']);

        expect(app(PaymentGateway::class))->toBeInstanceOf(StripeGateway::class)
            ->and(app(PaymentGateway::class)->completesCheckoutInstantly())->toBeFalse();
    });

    it('has no hosted checkout to send the owner to', function () {
        config(['settlo.payment_gateway' => 'simulated']);
        $subscription = Subscription::factory()->for($this->owner, 'user')->incomplete()->create();

        expect(fn () => app(PaymentGateway::class)->checkoutUrl($subscription, url('/app'), url('/app')))
            ->toThrow(LogicException::class);
    });

    it('allows an explicitly selected dummy gateway outside production', function () {
        app()->detectEnvironment(fn (): string => 'staging');
        config(['settlo.payment_gateway' => 'dummy']);

        expect(app(PaymentGateway::class))->toBeInstanceOf(DummyGateway::class);
    });

    it('never fake-renews dummy subscriptions in production', function () {
        $dummy = Subscription::factory()->for($this->owner, 'user')->active()->create(['current_period_end' => now()->subMinute()]);
        app()->detectEnvironment(fn (): string => 'production');
        config(['settlo.payment_gateway' => 'stripe', 'cashier.secret' => 'sk_test_fake']);

        $this->artisan('settlo:renew-subscriptions')
            ->expectsOutputToContain('not allowed')
            ->assertSuccessful();

        expect($dummy->fresh()->current_period_end->isPast())->toBeTrue()
            ->and($dummy->payments()->count())->toBe(0);
    });

    it('does not renew dummy subscriptions while Stripe is bound', function () {
        $dummy = Subscription::factory()->for($this->owner, 'user')->active()->create(['current_period_end' => now()->subMinute()]);
        config(['settlo.payment_gateway' => 'stripe', 'cashier.secret' => 'sk_test_fake']);

        $this->artisan('settlo:renew-subscriptions')->assertSuccessful();

        expect($dummy->fresh()->current_period_end->isPast())->toBeTrue();
    });
});

it('reads the owner inside a locked transaction before granting the trial (L3)', function () {
    $entity = newWorkspace($this->owner);
    $staleOwner = $entity->owner;
    $this->owner->forceFill(['trial_used_at' => now()])->save();

    $userQueriesInTransaction = [];
    DB::listen(function (QueryExecuted $query) use (&$userQueriesInTransaction): void {
        if (str_contains($query->sql, 'from "users"')) {
            $userQueriesInTransaction[] = $query->connection->transactionLevel() > 0;
        }
    });

    $subscription = $this->service->startWorkspaceSubscription($entity, $this->pro);

    expect($staleOwner->hasUsedTrial())->toBeFalse()
        ->and($subscription->status)->toBe(SubscriptionStatus::Incomplete)
        ->and($userQueriesInTransaction)->not->toBeEmpty()
        ->and($userQueriesInTransaction)->each->toBeTrue();
});

describe('Stripe coupons (H4)', function () {
    it('defaults the coupon ids to the ones settlo:stripe-sync-plans creates', function () {
        config(['settlo.billing.stripe_coupons' => [20 => null, 30 => '']]);
        $pricing = app(WorkspacePricing::class);

        expect($pricing->couponFor(0))->toBeNull()
            ->and($pricing->couponFor(20))->toBe('settlo-workspace-20')
            ->and($pricing->couponFor(30))->toBe('settlo-workspace-30');
    });

    it('uses a configured coupon id', function () {
        config(['settlo.billing.stripe_coupons.20' => 'custom_coupon']);

        expect(app(WorkspacePricing::class)->couponFor(20))->toBe('custom_coupon');
    });

    it('throws when a discount has no coupon', function () {
        expect(fn () => app(WorkspacePricing::class)->couponFor(25))
            ->toThrow(CheckoutUnavailableException::class);
    });
});

it('starts a trial of the configured length without discount for the first workspace', function () {
    config(['settlo.billing.trial_days' => 21]);
    $subscription = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);

    expect($subscription->status)->toBe(SubscriptionStatus::Trialing)
        ->and($subscription->trial_ends_at->isSameDay(now()->addDays(21)))->toBeTrue()
        ->and($subscription->discount_percent)->toBe(0)
        ->and($subscription->unit_price)->toBe('49.00')
        ->and($subscription->human_answers_quota)->toBe(1)
        ->and($subscription->payments()->count())->toBe(0)
        ->and($this->owner->fresh()->trial_used_at)->not->toBeNull();
});

it('requires payment with 20 % off for the second and 30 % off for the third workspace', function () {
    $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);

    $second = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);

    expect($second->status)->toBe(SubscriptionStatus::Incomplete)
        ->and($second->trial_ends_at)->toBeNull()
        ->and($second->human_answers_quota)->toBe(0)
        ->and($second->discount_percent)->toBe(20)
        ->and($second->unit_price)->toBe('39.20')
        ->and($second->businessEntity->canWrite())->toBeFalse();

    $this->service->activate($second);

    $third = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);

    expect($third->discount_percent)->toBe(30)
        ->and($third->unit_price)->toBe('34.30');
});

it('prices a yearly Pro first workspace at ten months', function () {
    $subscription = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro, BillingInterval::Year);

    expect($subscription->billing_interval)->toBe(BillingInterval::Year)
        ->and($subscription->unit_price)->toBe('490.00')
        ->and(app(WorkspacePricing::class)->describe($this->pro, BillingInterval::Year, 0))->toBe('CHF 490 / year · 2 months free')
        ->and(app(WorkspacePricing::class)->describe($this->pro, BillingInterval::Month, 20))->toBe('CHF 39.20 / month · 20 % multi-business discount');
});

it('does not restart the trial for an owner who already used it', function () {
    $this->owner->forceFill(['trial_used_at' => now()->subYear()])->save();

    $subscription = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);

    expect($subscription->status)->toBe(SubscriptionStatus::Incomplete)
        ->and($subscription->discount_percent)->toBe(0);
});

it('keeps the locked discount of the third workspace when the second is cancelled', function () {
    $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);
    $second = $this->service->activate($this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro));
    $third = $this->service->activate($this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro));

    $this->service->cancel($second);
    $this->service->expire($second);
    $this->service->renew($third->refresh());

    expect($third->refresh()->discount_percent)->toBe(30)
        ->and($third->unit_price)->toBe('34.30')
        ->and($third->payments()->latest('paid_at')->first()->amount)->toBe('34.30');
});

it('grants full Pro features during a Solo trial, per workspace', function () {
    $entity = newWorkspace($this->owner);
    $this->service->startWorkspaceSubscription($entity, $this->solo);

    expect($entity->fresh()->hasFeature(PlanFeature::TaxEngine))->toBeTrue()
        ->and($entity->fresh()->hasFeature(PlanFeature::AccountantAccess))->toBeTrue()
        ->and($this->owner->fresh()->hasFeatureInAnyWorkspace(PlanFeature::TaxEngine))->toBeTrue();
});

it('denies the tax engine to an active Solo workspace but allows it on Pro, when gating is enforced', function () {
    config(['settlo.enforce_feature_gates' => true]);

    $solo = Subscription::factory()->onPlan('solo', 0)->active()->create();
    $pro = Subscription::factory()->onPlan('pro', 1)->active()->create();

    expect($solo->businessEntity->hasFeature(PlanFeature::TaxEngine))->toBeFalse()
        ->and($pro->businessEntity->hasFeature(PlanFeature::TaxEngine))->toBeTrue();
});

describe('no paywalls by default (decision 8a)', function () {
    it('ships with feature gating off', function () {
        expect(config('settlo.enforce_feature_gates'))->toBeFalse();
    });

    it('grants every feature to any plan that may be written to', function () {
        $solo = Subscription::factory()->onPlan('solo', 0)->active()->create();

        expect($solo->businessEntity->hasFeature(PlanFeature::TaxEngine))->toBeTrue()
            ->and($solo->businessEntity->hasFeature(PlanFeature::VatForm300))->toBeTrue()
            ->and($solo->businessEntity->hasFeature(PlanFeature::YearEndExport))->toBeTrue();
    });

    it('grants nothing to a workspace whose subscription has lapsed', function () {
        $expired = Subscription::factory()->onPlan('pro', 1)->create(['status' => SubscriptionStatus::Expired]);

        expect($expired->businessEntity->hasFeature(PlanFeature::TaxEngine))->toBeFalse();
    });
});

it('upgrades immediately and records a payment', function () {
    $sub = Subscription::factory()->for($this->owner, 'user')->onPlan('solo', 0)->active()->create();

    $this->service->changePlan($sub, $this->pro);

    $sub->refresh();
    expect($sub->plan->code)->toBe('pro')
        ->and($sub->status)->toBe(SubscriptionStatus::Active)
        ->and($sub->unit_price)->toBe('49.00')
        ->and($sub->payments()->where('status', 'paid')->count())->toBe(1);
});

it('opens a yearly period when switching to yearly billing', function () {
    $sub = Subscription::factory()->for($this->owner, 'user')->onPlan('pro', 1)->active()->create();

    $this->service->changePlan($sub, $this->pro, BillingInterval::Year);

    $sub->refresh();
    expect($sub->billing_interval)->toBe(BillingInterval::Year)
        ->and($sub->unit_price)->toBe('490.00')
        ->and($sub->current_period_end->isSameDay(now()->addYearNoOverflow()))->toBeTrue();
});

it('defers a downgrade to period end', function () {
    $sub = Subscription::factory()->for($this->owner, 'user')->onPlan('pro', 1)->active()->create();

    $this->service->changePlan($sub, $this->solo);

    $sub->refresh();
    expect($sub->plan->code)->toBe('pro')
        ->and($sub->pending_plan_id)->toBe($this->solo->id);

    $this->service->renew($sub);
    expect($sub->refresh()->plan->code)->toBe('solo');
});

it('meters human-answer quota and blocks the last-credit double spend', function () {
    $sub = Subscription::factory()->for($this->owner, 'user')->onPlan('pro', 1)->active()->create([
        'human_answers_quota' => 1,
        'human_answers_used' => 0,
    ]);

    $this->service->consumeHumanAnswer($sub);
    expect($sub->fresh()->human_answers_used)->toBe(1);

    $this->service->consumeHumanAnswer($sub);
})->throws(QuotaExceededException::class);

it('locks the workspace read-only once expired', function () {
    $sub = Subscription::factory()->for($this->owner, 'user')->expired()->create();

    expect($sub->businessEntity->canWrite())->toBeFalse();

    $this->service->expire($sub);
    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('expires ended trials and notifies the owner', function () {
    $sub = Subscription::factory()->for($this->owner, 'user')->create(['trial_ends_at' => now()->subHour()]);

    Artisan::call('settlo:expire-trials');

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($this->owner->notifications()->count())->toBe(1);
});

it('keeps an ended trial that was converted at Stripe', function () {
    $sub = Subscription::factory()->for($this->owner, 'user')->create([
        'trial_ends_at' => now()->subHour(),
        'stripe_subscription_type' => 'workspace:converted',
    ]);
    StripeSubscription::create([
        'user_id' => $this->owner->getKey(),
        'type' => 'workspace:converted',
        'stripe_id' => 'sub_converted',
        'stripe_status' => 'active',
    ]);

    Artisan::call('settlo:expire-trials');

    expect($sub->fresh()->status)->toBe(SubscriptionStatus::Trialing);
});

it('renews only dummy-gateway subscriptions', function () {
    $dummy = Subscription::factory()->for($this->owner, 'user')->active()->create(['current_period_end' => now()->subMinute()]);
    $stripe = Subscription::factory()->active()->create(['current_period_end' => now()->subMinute(), 'gateway' => 'stripe']);

    Artisan::call('settlo:renew-subscriptions');

    expect($dummy->fresh()->current_period_end->isFuture())->toBeTrue()
        ->and($stripe->fresh()->current_period_end->isPast())->toBeTrue();
});

describe('simulated payments', function () {
    beforeEach(function () {
        config(['settlo.payment_gateway' => 'simulated']);
        $this->service = app(SubscriptionService::class);
    });

    it('renews simulated subscriptions wherever they are selected, and never Stripe rows', function () {
        app()->detectEnvironment(fn (): string => 'production');
        $simulated = Subscription::factory()->for($this->owner, 'user')->active()
            ->create(['gateway' => 'simulated', 'current_period_end' => now()->subMinute()]);
        $stripe = Subscription::factory()->active()
            ->create(['gateway' => 'stripe', 'current_period_end' => now()->subMinute()]);

        $this->artisan('settlo:renew-subscriptions')
            ->expectsOutputToContain('Renewed 1 subscription(s).')
            ->assertSuccessful();

        expect($simulated->fresh()->current_period_end->isFuture())->toBeTrue()
            ->and($simulated->payments()->count())->toBe(1)
            ->and($stripe->fresh()->current_period_end->isPast())->toBeTrue();
    });

    it('marks an incomplete workspace paid in-process without charging anything', function () {
        $this->owner->forceFill(['trial_used_at' => now()->subYear()])->save();
        $subscription = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);

        expect($subscription->status)->toBe(SubscriptionStatus::Incomplete);

        $this->service->payNow($subscription);
        $subscription->refresh();

        $payment = $subscription->payments()->sole();

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->gateway)->toBe('simulated')
            ->and($subscription->current_period_end->isFuture())->toBeTrue()
            ->and($payment->gateway)->toBe('simulated')
            ->and($payment->gateway_reference)->toBe('simulated:'.$subscription->getKey().':'.$subscription->current_period_start->getTimestamp())
            ->and($payment->amount)->toBe('49.00');
    });

    it('pays only once when the pay button is pressed twice', function () {
        $this->owner->forceFill(['trial_used_at' => now()->subYear()])->save();
        $subscription = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);

        $this->service->payNow($subscription);
        $periodEnd = $subscription->refresh()->current_period_end;

        $this->travel(5)->minutes();
        $this->service->payNow($subscription);

        expect($subscription->refresh()->payments()->count())->toBe(1)
            ->and($subscription->current_period_end->equalTo($periodEnd))->toBeTrue();
    });

    it('records one payment per period even when the same activation is replayed', function () {
        $this->freezeTime();
        $this->owner->forceFill(['trial_used_at' => now()->subYear()])->save();
        $subscription = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);

        $this->service->activate($subscription);
        $this->service->activate($subscription);

        $payment = $subscription->refresh()->payments()->sole();

        // The database refuses a duplicate too, whatever writes it.
        expect(fn () => SubscriptionPayment::create([
            ...$payment->only(['subscription_id', 'plan_id', 'amount', 'currency_code', 'status', 'gateway', 'gateway_reference']),
        ]))->toThrow(QueryException::class);
    });

    it('keeps the trial columns the MRR conversion metric reads when a trial pays early', function () {
        $subscription = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);
        $trialStartsAt = $subscription->trial_starts_at;
        $trialEndsAt = $subscription->trial_ends_at;
        $trialUsedAt = $this->owner->fresh()->trial_used_at;

        $this->service->payNow($subscription);
        $subscription->refresh();

        expect($subscription->status)->toBe(SubscriptionStatus::Active)
            ->and($subscription->trial_starts_at->equalTo($trialStartsAt))->toBeTrue()
            ->and($subscription->trial_ends_at->equalTo($trialEndsAt))->toBeTrue()
            ->and($this->owner->fresh()->trial_used_at->equalTo($trialUsedAt))->toBeTrue();
    });

    it('keeps the locked discount tier instead of recomputing it', function () {
        $this->service->activate($this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro));
        $second = $this->service->startWorkspaceSubscription(newWorkspace($this->owner), $this->pro);

        expect($second->discount_percent)->toBe(20);

        $this->service->cancel($second);
        $this->service->payNow($second);

        expect($second->refresh()->discount_percent)->toBe(20)
            ->and($second->unit_price)->toBe('39.20');
    });
});

it('refuses to sync plans to Stripe without a secret', function () {
    $this->artisan('settlo:stripe-sync-plans')
        ->expectsOutputToContain('STRIPE_SECRET is not configured.')
        ->assertFailed();
});
