<?php

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\StripeSubscription;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Database\Seeders\CantonSeeder;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed([CantonSeeder::class, PlanSeeder::class]);

    // No webhook secret in tests: Cashier skips the signature check.
    config(['cashier.webhook.secret' => null]);

    Plan::where('code', 'pro')->firstOrFail()->forceFill([
        'stripe_price_monthly_id' => 'price_pro_month',
        'stripe_price_yearly_id' => 'price_pro_year',
    ])->save();
    Plan::where('code', 'confidence')->firstOrFail()->forceFill([
        'stripe_price_monthly_id' => 'price_confidence_month',
        'stripe_price_yearly_id' => 'price_confidence_year',
    ])->save();

    $this->owner = User::factory()->owner()->create();
    $this->owner->forceFill(['stripe_id' => 'cus_test_owner'])->save();
    $this->entity = BusinessEntity::factory()->for($this->owner, 'owner')->create();
    $this->subscription = Subscription::factory()->forEntity($this->entity)->onPlan('pro', 1)->incomplete()->create([
        'gateway' => 'stripe',
        'discount_percent' => 20,
        'stripe_subscription_type' => 'workspace:'.$this->entity->getKey(),
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeSubscriptionObject(Subscription $subscription, array $overrides = []): array
{
    $start = now()->startOfDay()->getTimestamp();
    $end = now()->startOfDay()->addYear()->getTimestamp();

    return array_replace_recursive([
        'id' => 'sub_test_1',
        'object' => 'subscription',
        'customer' => 'cus_test_owner',
        'status' => 'active',
        'cancel_at_period_end' => false,
        'canceled_at' => null,
        'cancel_at' => null,
        'ended_at' => null,
        'trial_end' => null,
        'metadata' => [
            'type' => $subscription->stripe_subscription_type,
            'name' => $subscription->stripe_subscription_type,
            'business_entity_id' => $subscription->business_entity_id,
            'settlo_subscription_id' => $subscription->getKey(),
        ],
        'items' => [
            'data' => [[
                'id' => 'si_test_1',
                'quantity' => 1,
                'current_period_start' => $start,
                'current_period_end' => $end,
                'price' => ['id' => 'price_confidence_year', 'product' => 'prod_confidence'],
            ]],
        ],
    ], $overrides);
}

function postStripeEvent(string $type, array $object): void
{
    test()->postJson('/stripe/webhook', [
        'id' => 'evt_'.uniqid(),
        'type' => $type,
        'data' => ['object' => $object],
    ])->assertOk();
}

it('syncs the workspace subscription from customer.subscription.updated', function () {
    postStripeEvent('customer.subscription.updated', stripeSubscriptionObject($this->subscription));

    $subscription = $this->subscription->fresh();

    expect($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan->code)->toBe('confidence')
        ->and($subscription->billing_interval)->toBe(BillingInterval::Year)
        ->and($subscription->unit_price)->toBe('792.00')
        ->and($subscription->human_answers_quota)->toBe(3)
        ->and($subscription->current_period_start->isSameDay(now()))->toBeTrue()
        ->and($subscription->current_period_end->isSameDay(now()->addYear()))->toBeTrue()
        ->and($subscription->gateway_subscription_id)->toBe('sub_test_1')
        ->and($this->entity->fresh()->canWrite())->toBeTrue()
        ->and(StripeSubscription::where('stripe_id', 'sub_test_1')->value('type'))->toBe($this->subscription->stripe_subscription_type);
});

it('falls back to the Cashier type when the Settlo id is missing', function () {
    $object = stripeSubscriptionObject($this->subscription, ['status' => 'trialing', 'trial_end' => now()->addDays(5)->getTimestamp()]);
    unset($object['metadata']['settlo_subscription_id']);

    postStripeEvent('customer.subscription.created', $object);

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Trialing)
        ->and($this->subscription->fresh()->trial_ends_at->isSameDay(now()->addDays(5)))->toBeTrue();
});

it('maps cancellation and pending cancellation', function () {
    postStripeEvent('customer.subscription.updated', stripeSubscriptionObject($this->subscription, [
        'cancel_at_period_end' => true,
        'canceled_at' => now()->getTimestamp(),
    ]));

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($this->subscription->fresh()->cancel_at_period_end)->toBeTrue();

    postStripeEvent('customer.subscription.deleted', stripeSubscriptionObject($this->subscription, [
        'status' => 'canceled',
        'ended_at' => now()->subMinute()->getTimestamp(),
        'canceled_at' => now()->subMinute()->getTimestamp(),
    ]));

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Expired)
        ->and($this->entity->fresh()->canWrite())->toBeFalse();
});

it('ignores events for another customer', function () {
    postStripeEvent('customer.subscription.updated', stripeSubscriptionObject($this->subscription, [
        'customer' => 'cus_someone_else',
    ]));

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Incomplete);
});

it('records a paid invoice exactly once', function () {
    $invoice = [
        'id' => 'in_test_1',
        'object' => 'invoice',
        'customer' => 'cus_test_owner',
        'amount_paid' => 3920,
        'currency' => 'chf',
        'created' => now()->getTimestamp(),
        'parent' => [
            'subscription_details' => [
                'subscription' => 'sub_test_1',
                'metadata' => [
                    'type' => $this->subscription->stripe_subscription_type,
                    'settlo_subscription_id' => $this->subscription->getKey(),
                ],
            ],
        ],
        'lines' => ['data' => [[
            'period' => ['start' => now()->getTimestamp(), 'end' => now()->addMonth()->getTimestamp()],
        ]]],
    ];

    postStripeEvent('invoice.payment_succeeded', $invoice);
    postStripeEvent('invoice.payment_succeeded', $invoice);

    $payments = SubscriptionPayment::where('gateway_reference', 'in_test_1')->get();

    expect($payments)->toHaveCount(1)
        ->and($payments->first()->amount)->toBe('39.20')
        ->and($payments->first()->currency_code)->toBe('CHF')
        ->and($payments->first()->gateway)->toBe('stripe')
        ->and($payments->first()->subscription_id)->toBe($this->subscription->getKey());
});

it('marks the workspace past due and notifies the owner when a payment fails', function () {
    StripeSubscription::create([
        'user_id' => $this->owner->getKey(),
        'type' => $this->subscription->stripe_subscription_type,
        'stripe_id' => 'sub_test_1',
        'stripe_status' => 'past_due',
    ]);
    $this->subscription->forceFill(['status' => SubscriptionStatus::Active])->save();

    postStripeEvent('invoice.payment_failed', [
        'id' => 'in_test_failed',
        'object' => 'invoice',
        'customer' => 'cus_test_owner',
        'amount_paid' => 0,
        'parent' => ['subscription_details' => ['subscription' => 'sub_test_1', 'metadata' => []]],
    ]);

    expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::PastDue)
        ->and($this->entity->fresh()->canWrite())->toBeTrue()
        ->and($this->owner->notifications()->count())->toBe(1)
        ->and($this->owner->notifications()->first()->data['title'])->toBe("Payment failed for {$this->entity->name}");
});

describe('unsigned webhooks (H3)', function () {
    it('refuses webhooks outside local and tests when no signing secret is configured', function (string $environment) {
        app()->detectEnvironment(fn (): string => $environment);
        config(['cashier.secret' => 'sk_live_fake', 'cashier.webhook.secret' => null]);

        $this->postJson('/stripe/webhook', [
            'id' => 'evt_forged',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => stripeSubscriptionObject($this->subscription)],
        ])->assertForbidden();

        expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Incomplete)
            ->and(StripeSubscription::count())->toBe(0);
    })->with(['production', 'staging']);

    it('requires a valid signature once the signing secret is configured', function () {
        app()->detectEnvironment(fn (): string => 'production');
        config(['settlo.payment_gateway' => 'stripe', 'cashier.secret' => 'sk_live_fake', 'cashier.webhook.secret' => 'whsec_test']);

        $payload = json_encode([
            'id' => 'evt_forged',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => stripeSubscriptionObject($this->subscription)],
        ]);

        $this->call('POST', '/stripe/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=invalid',
        ], content: $payload)->assertForbidden();

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test');

        $this->call('POST', '/stripe/webhook', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], content: $payload)->assertOk();

        expect($this->subscription->fresh()->status)->toBe(SubscriptionStatus::Active);
    });

    it('does not affect other routes', function () {
        app()->detectEnvironment(fn (): string => 'production');
        config(['cashier.webhook.secret' => null]);

        $this->get('/up')->assertOk();
    });
});
