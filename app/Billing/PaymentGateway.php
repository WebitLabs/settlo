<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\User;

/**
 * Payment provider abstraction. The POC ships a DummyGateway; a real provider
 * (e.g. Stripe) can be dropped in behind this contract without touching the
 * subscription lifecycle. The gateway NEVER trusts client-supplied success —
 * callers pass server-resolved Plan/User only.
 */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Ensure a customer record exists at the gateway for this user, returning
     * the gateway customer id.
     */
    public function ensureCustomer(User $user): string;

    /**
     * Charge the user for one billing period of the given plan.
     */
    public function charge(User $user, Plan $plan): ChargeResult;
}
