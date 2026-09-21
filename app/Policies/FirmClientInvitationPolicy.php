<?php

namespace App\Policies;

use App\Models\FirmClientInvitation;
use App\Models\User;
use App\Policies\Concerns\ChecksFirmMembership;

/**
 * Default-deny. Any member of a firm may see the invitations it has sent, but
 * only a firm owner may send, re-send or revoke one — revoking invalidates a
 * live token, so it is a team-management action like the other owner-gated
 * ones. A superadmin oversees every firm's invitations.
 */
class FirmClientInvitationPolicy
{
    use ChecksFirmMembership;

    public function viewAny(User $user): bool
    {
        return $user->isSuperadmin() || $this->belongsToAnyFirm($user);
    }

    public function view(User $user, FirmClientInvitation $invitation): bool
    {
        return $user->isSuperadmin() || $this->isFirmMember($user, $invitation->accounting_firm_id);
    }

    /**
     * Sending an invitation grants a firm access to a client's books, so it is
     * an owner-only action like the rest of team management. There is no record
     * to scope to here, so ownership of any firm is the check; the resource
     * narrows it to the current firm tenant.
     */
    public function create(User $user): bool
    {
        return $user->isSuperadmin() || $this->ownsAnyFirm($user);
    }

    public function update(User $user, FirmClientInvitation $invitation): bool
    {
        return $user->isSuperadmin() || $this->isFirmOwner($user, $invitation->accounting_firm_id);
    }

    public function delete(User $user, FirmClientInvitation $invitation): bool
    {
        return $user->isSuperadmin() || $this->isFirmOwner($user, $invitation->accounting_firm_id);
    }

    public function deleteAny(User $user): bool
    {
        return $user->isSuperadmin();
    }

    public function restore(User $user, FirmClientInvitation $invitation): bool
    {
        return false;
    }

    public function forceDelete(User $user, FirmClientInvitation $invitation): bool
    {
        return false;
    }
}
