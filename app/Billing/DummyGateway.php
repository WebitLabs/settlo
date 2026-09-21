<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Local stand-in for a real payment provider. Every charge "succeeds"
 * instantly and produces a fake reference; checkout is a signed local URL
 * that activates the subscription. No external calls, no card data. It hands
 * out paid plans for free, so it is only ever allowed outside production
 * ({@see self::isAllowed()}). {@see SimulatedGateway} extends it with the
 * in-process variant that is opt-in everywhere.
 */
class DummyGateway implements PaymentGateway
{
    /**
     * Local development and tests always may use the dummy gateway; any other
     * non-production environment only when it is selected explicitly
     * (SETTLO_PAYMENT_GATEWAY=dummy). Production never may.
     */
    public static function isAllowed(): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }

        return ! app()->environment('production') && config('settlo.payment_gateway') === 'dummy';
    }

    public function name(): string
    {
        return 'dummy';
    }

    public function completesCheckoutInstantly(): bool
    {
        return false;
    }

    public function assertCanCheckout(Plan $plan, BillingInterval $interval, int $discount): void {}

    public function ensureCustomer(User $user): string
    {
        return 'dummy_cus_'.$user->getKey();
    }

    public function checkoutUrl(Subscription $subscription, string $successUrl, string $cancelUrl): string
    {
        return URL::signedRoute('billing.dummy-checkout', [
            'subscription' => $subscription->getKey(),
            'return' => $successUrl,
        ]);
    }

    public function swap(Subscription $subscription, Plan $plan, BillingInterval $interval): void {}

    public function cancel(Subscription $subscription): void {}

    public function resume(Subscription $subscription): void {}

    public function billingPortalUrl(User $user, string $returnUrl): ?string
    {
        return null;
    }

    public function charge(User $user, Plan $plan): ChargeResult
    {
        return ChargeResult::success('dummy_ch_'.Str::random(24), $this->name());
    }
}
