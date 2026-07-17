<?php

namespace App\Billing;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Local stand-in for a real payment provider. Every charge "succeeds"
 * instantly and produces a fake reference. No external calls, no card data.
 */
final class DummyGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'dummy';
    }

    public function ensureCustomer(User $user): string
    {
        return 'dummy_cus_'.$user->getKey();
    }

    public function charge(User $user, Plan $plan): ChargeResult
    {
        return ChargeResult::success('dummy_ch_'.Str::random(24), $this->name());
    }
}
