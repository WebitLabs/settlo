<?php

use App\Billing\CheckoutUnavailableException;
use App\Billing\StripeGateway;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\StripeSubscription;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CantonSeeder;
use Database\Seeders\PlanSeeder;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * An in-memory Stripe HTTP client: answers the calls a checkout makes and
 * records every request, so no test ever reaches the Stripe API.
 */
function fakeStripeHttpClient(): ClientInterface
{
    return new class implements ClientInterface
    {
        /** @var list<array{method: string, path: string, params: array<string, mixed>}> */
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $path = (string) parse_url($absUrl, PHP_URL_PATH);
            $this->requests[] = ['method' => $method, 'path' => $path, 'params' => $params];

            $body = match (true) {
                $method === 'post' && $path === '/v1/customers' => ['id' => 'cus_fake', 'object' => 'customer'],
                $method === 'get' && str_starts_with($path, '/v1/customers/') => ['id' => basename($path), 'object' => 'customer'],
                $method === 'get' && str_starts_with($path, '/v1/coupons/') => [
                    'id' => basename($path), 'object' => 'coupon', 'percent_off' => 20, 'amount_off' => null, 'duration' => 'forever',
                ],
                $method === 'post' && $path === '/v1/checkout/sessions' => [
                    'id' => 'cs_test_1', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1',
                ],
                default => null,
            };

            return $body === null
                ? [json_encode(['error' => ['message' => "Unexpected {$method} {$path}"]]), 400, []]
                : [json_encode($body), 200, []];
        }

        /**
         * @return array<string, mixed>
         */
        public function checkoutSession(): array
        {
            return collect($this->requests)->firstWhere('path', '/v1/checkout/sessions')['params'] ?? [];
        }
    };
}

beforeEach(function () {
    $this->seed([CantonSeeder::class, PlanSeeder::class]);
    config(['cashier.secret' => 'sk_test_fake', 'settlo.payment_gateway' => 'stripe']);

    $this->pro = Plan::where('code', 'pro')->firstOrFail();
    $this->pro->forceFill(['stripe_price_monthly_id' => 'price_pro_month', 'stripe_price_yearly_id' => 'price_pro_year'])->save();

    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->for($this->owner, 'owner')->create();
    $this->subscription = Subscription::factory()->forEntity($this->entity)->onPlan('pro', 1)->incomplete()->create([
        'gateway' => 'stripe',
        'discount_percent' => 20,
    ]);

    $this->http = fakeStripeHttpClient();
    ApiRequestor::setHttpClient($this->http);
    $this->gateway = app(StripeGateway::class);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

it('creates a Checkout session with the price, the discount coupon and the workspace metadata', function () {
    $url = $this->gateway->checkoutUrl($this->subscription, 'https://settlo.test/app/billing?checkout=success', 'https://settlo.test/app/billing');

    $session = $this->http->checkoutSession();
    $type = 'workspace:'.$this->entity->getKey();

    expect($url)->toBe('https://checkout.stripe.com/c/pay/cs_test_1')
        ->and($session['mode'])->toBe('subscription')
        ->and($session['customer'])->toBe('cus_fake')
        ->and($session['line_items'][0]['price'])->toBe('price_pro_month')
        ->and($session['discounts'])->toBe([['coupon' => 'settlo-workspace-20']])
        ->and($session['subscription_data']['metadata']['type'])->toBe($type)
        ->and($session['subscription_data']['metadata']['settlo_subscription_id'])->toBe($this->subscription->getKey())
        ->and($session['client_reference_id'])->toBe($this->subscription->getKey())
        ->and($session['success_url'])->toBe('https://settlo.test/app/billing?checkout=success')
        ->and($session['cancel_url'])->toBe('https://settlo.test/app/billing')
        ->and($session)->not->toHaveKey('subscription_data.trial_end')
        ->and($this->subscription->fresh()->stripe_subscription_type)->toBe($type)
        ->and($this->owner->fresh()->stripe_id)->toBe('cus_fake');
});

it('adds the success flag once and carries a running trial over to Stripe', function () {
    $this->subscription->forceFill([
        'status' => SubscriptionStatus::Trialing,
        'trial_ends_at' => now()->addDays(10),
        'discount_percent' => 0,
    ])->save();

    $this->gateway->checkoutUrl($this->subscription, 'https://settlo.test/app/billing', 'https://settlo.test/app/billing');

    $session = $this->http->checkoutSession();

    expect($session['success_url'])->toBe('https://settlo.test/app/billing?checkout=success')
        ->and($session)->not->toHaveKey('discounts')
        ->and($session['subscription_data']['trial_end'])->toBe($this->subscription->trial_ends_at->getTimestamp());
});

it('refuses a second checkout while Stripe already bills the workspace (H2)', function (string $status) {
    StripeSubscription::create([
        'user_id' => $this->owner->getKey(),
        'type' => 'workspace:'.$this->entity->getKey(),
        'stripe_id' => 'sub_live',
        'stripe_status' => $status,
    ]);

    expect(fn () => $this->gateway->checkoutUrl($this->subscription, 'https://settlo.test/a', 'https://settlo.test/b'))
        ->toThrow(CheckoutUnavailableException::class, 'already paid for');

    expect($this->http->requests)->toBe([]);
})->with(['trialing', 'active', 'past_due']);

it('allows a new checkout after the previous Stripe subscription ended', function () {
    StripeSubscription::create([
        'user_id' => $this->owner->getKey(),
        'type' => 'workspace:'.$this->entity->getKey(),
        'stripe_id' => 'sub_old',
        'stripe_status' => 'canceled',
    ]);

    expect($this->gateway->checkoutUrl($this->subscription, 'https://settlo.test/a', 'https://settlo.test/b'))
        ->toBe('https://checkout.stripe.com/c/pay/cs_test_1');
});

it('refuses a checkout for a plan without a Stripe price before calling Stripe (M7)', function () {
    $this->pro->forceFill(['stripe_price_monthly_id' => null])->save();

    expect(fn () => $this->gateway->checkoutUrl($this->subscription->fresh(), 'https://settlo.test/a', 'https://settlo.test/b'))
        ->toThrow(CheckoutUnavailableException::class, 'no Stripe price');

    expect(fn () => $this->gateway->assertCanCheckout($this->pro->fresh(), BillingInterval::Month, 0))
        ->toThrow(CheckoutUnavailableException::class)
        ->and($this->http->requests)->toBe([]);

    $this->gateway->assertCanCheckout($this->pro->fresh(), BillingInterval::Year, 30);
});

it('refuses a discount that has no Stripe coupon (H4)', function () {
    expect(fn () => $this->gateway->assertCanCheckout($this->pro, BillingInterval::Month, 15))
        ->toThrow(CheckoutUnavailableException::class, '15 %');
});

it('knows whether Stripe already bills a workspace', function () {
    expect(StripeGateway::hasLiveSubscription($this->subscription))->toBeFalse();

    $this->subscription->forceFill(['stripe_subscription_type' => 'workspace:'.$this->entity->getKey()])->save();
    StripeSubscription::create([
        'user_id' => User::factory()->owner()->create()->getKey(),
        'type' => $this->subscription->stripe_subscription_type,
        'stripe_id' => 'sub_other_user',
        'stripe_status' => 'active',
    ]);

    expect(StripeGateway::hasLiveSubscription($this->subscription))->toBeFalse();
});
