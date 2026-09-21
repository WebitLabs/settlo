<?php

namespace App\Policies;

use App\Models\SubscriptionPayment;
use App\Models\User;

/**
 * Default-deny. Payment rows are an append-only billing ledger: the superadmin
 * panel reads them, an owner may read the ones belonging to a workspace they
 * own, and nobody may create, edit or delete one through a panel — they are
 * written only by the gateway/webhook path.
 */
class SubscriptionPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, SubscriptionPayment $payment): bool
    {
        if ($user->isSuperadmin()) {
            return true;
        }

        return $user->isOwner()
            && $payment->subscription()
                ->whereHas('businessEntity', fn ($query) => $query->where('owner_id', $user->getKey()))
                ->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SubscriptionPayment $payment): bool
    {
        return false;
    }

    public function delete(User $user, SubscriptionPayment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, SubscriptionPayment $payment): bool
    {
        return false;
    }

    public function forceDelete(User $user, SubscriptionPayment $payment): bool
    {
        return false;
    }
}
