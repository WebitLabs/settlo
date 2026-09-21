<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;
use LogicException;

/**
 * Simulated payments: pressing "pay" marks the workspace subscription as paid
 * in-process — no hosted checkout, no external call, no card data and no money
 * moved. It GRANTS PAID PLANS WITHOUT CHARGING ANYBODY, so it is never a
 * fallback: it is bound only when SETTLO_PAYMENT_GATEWAY=simulated is set
 * explicitly, and the UI always tells the owner that the payment is simulated.
 * Selecting it in a live environment is a deliberate product decision.
 */
final class SimulatedGateway extends DummyGateway
{
    public function name(): string
    {
        return 'simulated';
    }

    public function completesCheckoutInstantly(): bool
    {
        return true;
    }

    public function ensureCustomer(User $user): string
    {
        return 'simulated_cus_'.$user->getKey();
    }

    /**
     * Unreachable: an instant gateway is paid in-process, never through a
     * hosted checkout page.
     */
    public function checkoutUrl(Subscription $subscription, string $successUrl, string $cancelUrl): string
    {
        throw new LogicException('Simulated payments complete in-process; there is no checkout URL.');
    }

    public function charge(User $user, Plan $plan): ChargeResult
    {
        return ChargeResult::success('simulated_ch_'.Str::random(24), $this->name());
    }
}
