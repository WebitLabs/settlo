<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

/**
 * Default-deny. A workspace subscription is billing state, never edited as a
 * record: the superadmin panel reads it (and mutates it only through the
 * audited extend-trial / comp / cancel actions, which run through
 * SubscriptionService), and the owner sees their own through the billing pages.
 * Every CRUD write ability is hard-denied so no resource, bulk action or
 * crafted request can rewrite a subscription row directly.
 */
class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function view(User $user, Subscription $subscription): bool
    {
        if ($user->isSuperadmin()) {
            return true;
        }

        return $user->isOwner()
            && $subscription->businessEntity()->where('owner_id', $user->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return false;
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Subscription $subscription): bool
    {
        return false;
    }

    public function forceDelete(User $user, Subscription $subscription): bool
    {
        return false;
    }
}
