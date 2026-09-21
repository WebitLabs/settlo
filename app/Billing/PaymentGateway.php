<?php

namespace App\Billing;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

/**
 * Payment provider abstraction: Stripe (Cashier) in test/production mode, or
 * the local DummyGateway in local development and tests only
 * ({@see DummyGateway::isAllowed()}). The gateway NEVER trusts client-supplied
 * success — callers pass server-resolved models only, and Stripe state arrives
 * through signed webhooks.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Whether pressing "pay" completes the payment in-process, with no hosted
     * checkout to send the owner to ({@see SimulatedGateway}). Gateways that
     * answer true never have their {@see self::checkoutUrl()} called.
     */
    public function completesCheckoutInstantly(): bool;

    /**
     * Ensure a customer record exists at the gateway for this user, returning
     * the gateway customer id.
     */
    public function ensureCustomer(User $user): string;

    /**
     * Throw when a checkout for this plan, interval and discount cannot be
     * started (checked before a workspace is provisioned).
     *
     * @throws CheckoutUnavailableException
     */
    public function assertCanCheckout(Plan $plan, BillingInterval $interval, int $discount): void;

    /**
     * The URL where the owner completes payment for a workspace subscription.
     *
     * @throws CheckoutUnavailableException
     */
    public function checkoutUrl(Subscription $subscription, string $successUrl, string $cancelUrl): string;

    /**
     * Move a workspace subscription to another plan / interval.
     */
    public function swap(Subscription $subscription, Plan $plan, BillingInterval $interval): void;

    /**
     * Cancel at the end of the current period.
     */
    public function cancel(Subscription $subscription): void;

    /**
     * Undo a pending cancellation.
     */
    public function resume(Subscription $subscription): void;

    /**
     * The self-service portal (payment methods & invoices), if the gateway has one.
     */
    public function billingPortalUrl(User $user, string $returnUrl): ?string;

    /**
     * Charge the user for one billing period of the given plan (locally
     * renewed gateways only).
     */
    public function charge(User $user, Plan $plan): ChargeResult;
}
