<?php

namespace App\Policies;

use App\Models\AccountingFirmMember;
use App\Models\User;
use App\Policies\Concerns\ChecksFirmMembership;

/**
 * Default-deny. Any member of a firm may see its roster; only a firm owner may
 * add, promote/demote or remove a member, and never themselves (the "last
 * owner" and "not yourself" rules stay in the action, which also re-checks
 * ownership server-side). A superadmin manages every firm's team.
 */
class AccountingFirmMemberPolicy
{
    use ChecksFirmMembership;

    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $this->belongsToAnyFirm($user);
    }

    public function view(User $user, AccountingFirmMember $member): bool
    {
        return $user->isSuperadmin() || $this->isFirmMember($user, $member->accounting_firm_id);
    }

    public function create(User $user): bool
    {
        return $user->isSuperadmin() || $this->belongsToAnyFirm($user);
    }

    public function update(User $user, AccountingFirmMember $member): bool
    {
        return $user->isSuperadmin() || $this->isFirmOwner($user, $member->accounting_firm_id);
    }

    public function delete(User $user, AccountingFirmMember $member): bool
    {
        if ($user->isSuperadmin()) {
            return true;
        }

        return $this->isFirmOwner($user, $member->accounting_firm_id)
            && $member->user_id !== $user->getKey();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function restore(User $user, AccountingFirmMember $member): bool
    {
        return false;
    }

    public function forceDelete(User $user, AccountingFirmMember $member): bool
    {
        return false;
    }
}
