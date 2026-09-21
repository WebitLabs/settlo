<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Listeners\HandleStripeWebhook;
use App\Models\Plan;
use App\Models\StripeSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\WorkspacePricing;
use LogicException;

/**
 * Stripe via Laravel Cashier (one Stripe customer per user, one Stripe
 * subscription per business workspace, Cashier type `workspace:{uuid}`).
 * Stripe renews and prorates itself; the domain subscription is synced from
 * webhooks ({@see HandleStripeWebhook}).
 */
final class StripeGateway implements PaymentGateway
{
    /**
     * Cashier (Stripe) statuses of a subscription that is still being paid for.
     *
     * @var list<string>
     */
    public const array LIVE_STATUSES = ['trialing', 'active', 'past_due'];

    public function __construct(private readonly WorkspacePricing $pricing) {}

    /**
     * Whether Stripe already bills this workspace (a trialing, active or past-due
     * Cashier subscription of its type exists), so a new checkout would charge
     * the owner twice.
     */
    public static function hasLiveSubscription(Subscription $subscription): bool
    {
        if (blank($subscription->stripe_subscription_type)) {
            return false;
        }

        return StripeSubscription::query()
            ->where('user_id', $subscription->user_id)
            ->where('type', $subscription->stripe_subscription_type)
            ->whereIn('stripe_status', self::LIVE_STATUSES)
            ->exists();
    }

    public function name(): string
    {
        return 'stripe';
    }

    public function completesCheckoutInstantly(): bool
    {
        return false;
    }

    public function ensureCustomer(User $user): string
    {
        return $user->createOrGetStripeCustomer([
            'name' => $user->getFilamentName(),
            'email' => $user->email,
            'preferred_locales' => [$user->preferred_language ?? 'en'],
        ])->id;
    }

    public function assertCanCheckout(Plan $plan, BillingInterval $interval, int $discount): void
    {
        if (blank($plan->stripePriceId($interval))) {
            throw CheckoutUnavailableException::missingPrice();
        }

        $this->pricing->couponFor($discount);
    }

    public function checkoutUrl(Subscription $subscription, string $successUrl, string $cancelUrl): string
    {
        $user = $subscription->user;
        $plan = $subscription->plan;
        $interval = $subscription->billing_interval ?? BillingInterval::Month;

        if ($user === null || $plan === null) {
            throw CheckoutUnavailableException::missingPrice();
        }

        $subscription->stripe_subscription_type ??= 'workspace:'.$subscription->business_entity_id;

        if (self::hasLiveSubscription($subscription)) {
            throw CheckoutUnavailableException::alreadySubscribed();
        }

        $this->assertCanCheckout($plan, $interval, (int) $subscription->discount_percent);
        $priceId = (string) $plan->stripePriceId($interval);

        $this->ensureCustomer($user);

        $subscription->save();

        $builder = $user->newSubscription((string) $subscription->stripe_subscription_type, $priceId)
            ->withMetadata([
                'business_entity_id' => $subscription->business_entity_id,
                'settlo_subscription_id' => $subscription->getKey(),
            ]);

        if ($coupon = $this->pricing->couponFor((int) $subscription->discount_percent)) {
            $builder->withCoupon($coupon);
        }

        if ($subscription->status === SubscriptionStatus::Trialing && $subscription->trial_ends_at?->isFuture()) {
            $builder->trialUntil($subscription->trial_ends_at);
        }

        return $builder->checkout([
            'success_url' => str_contains($successUrl, 'checkout=success')
                ? $successUrl
                : $successUrl.(str_contains($successUrl, '?') ? '&' : '?').'checkout=success',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $subscription->getKey(),
            'billing_address_collection' => 'required',
        ])->url;
    }

    public function swap(Subscription $subscription, Plan $plan, BillingInterval $interval): void
    {
        $priceId = $plan->stripePriceId($interval);

        if (blank($priceId)) {
            throw new LogicException('The plan has no Stripe price. Run `php artisan settlo:stripe-sync-plans` first.');
        }

        $subscription->stripeSubscription()?->swap($priceId);
    }

    public function cancel(Subscription $subscription): void
    {
        $subscription->stripeSubscription()?->cancel();
    }

    public function resume(Subscription $subscription): void
    {
        $subscription->stripeSubscription()?->resume();
    }

    public function billingPortalUrl(User $user, string $returnUrl): ?string
    {
        $this->ensureCustomer($user);

        return $user->billingPortalUrl($returnUrl);
    }

    public function charge(User $user, Plan $plan): ChargeResult
    {
        throw new LogicException('Stripe renews subscriptions itself; local charges are not supported.');
    }
}
