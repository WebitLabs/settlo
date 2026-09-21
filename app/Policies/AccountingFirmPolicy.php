<?php

namespace App\Policies;

use App\Models\AccountingFirm;
use App\Models\User;
use App\Policies\Concerns\ChecksFirmMembership;

/**
 * Default-deny. A superadmin manages every firm from the admin panel; a firm's
 * own members may read their firm and only its owners may change its profile.
 * Firms are never created or deleted from the firm panel.
 */
class AccountingFirmPolicy
{
    use ChecksFirmMembership;

    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $this->belongsToAnyFirm($user);
    }

    public function view(User $user, AccountingFirm $firm): bool
    {
        return $user->isSuperadmin() || $this->isFirmMember($user, $firm->getKey());
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function update(User $user, AccountingFirm $firm): bool
    {
        return $user->isSuperadmin() || $this->isFirmOwner($user, $firm->getKey());
    }

    public function delete(User $user, AccountingFirm $firm): bool
    {
        return $user->isSuperadmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function restore(User $user, AccountingFirm $firm): bool
    {
        return $user->isSuperadmin();
    }

    public function forceDelete(User $user, AccountingFirm $firm): bool
    {
        return $user->isSuperadmin();
    }
}
