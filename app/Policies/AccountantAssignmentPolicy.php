<?php

namespace App\Policies;

use App\Models\AccountantAssignment;
use App\Models\User;
use App\Policies\Concerns\ChecksFirmMembership;

/**
 * Default-deny. An assignment is the link that grants a firm access to a
 * client's books, so it is never created or deleted from a panel: it is minted
 * by the invitation-accept flow and revoked (soft, stamped) by the audited
 * admin action. Members of the owning firm and a superadmin may read it.
 */
class AccountantAssignmentPolicy
{
    use ChecksFirmMembership;

    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $this->belongsToAnyFirm($user);
    }

    public function view(User $user, AccountantAssignment $assignment): bool
    {
        return $user->isSuperadmin() || $this->isFirmMember($user, $assignment->accounting_firm_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AccountantAssignment $assignment): bool
    {
        return $user->isSuperadmin();
    }

    public function delete(User $user, AccountantAssignment $assignment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, AccountantAssignment $assignment): bool
    {
        return false;
    }

    public function forceDelete(User $user, AccountantAssignment $assignment): bool
    {
        return false;
    }
}
