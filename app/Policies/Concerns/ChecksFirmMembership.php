<?php

namespace App\Policies\Concerns;

use App\Models\AccountingFirmMember;
use App\Models\User;

/**
 * Firm-panel authorization is membership based: an accountant may read what the
 * firms they belong to own, and only a firm *owner* may mutate the firm, its
 * team or its client invitations. Every check re-derives membership from the
 * pivot table so a crafted tenant id can never widen access.
 */
trait ChecksFirmMembership
{
    /**
     * Whether the user is a member (of any role) of the given firm.
     */
    protected function isFirmMember(User $user, ?string $firmId): bool
    {
        if ($firmId === null || ! $user->isAccountant()) {
            return false;
        }

        return AccountingFirmMember::query()
            ->where('accounting_firm_id', $firmId)
            ->where('user_id', $user->getKey())
            ->exists();
    }

    /**
     * Whether the user is an owner of the given firm.
     */
    protected function isFirmOwner(User $user, ?string $firmId): bool
    {
        if ($firmId === null || ! $user->isAccountant()) {
            return false;
        }

        return AccountingFirmMember::query()
            ->where('accounting_firm_id', $firmId)
            ->where('user_id', $user->getKey())
            ->where('is_owner', true)
            ->exists();
    }

    /**
     * Whether the user belongs to at least one firm — the listing ("viewAny")
     * counterpart of isFirmMember, which has no record to scope to.
     */
    protected function belongsToAnyFirm(User $user): bool
    {
        return $user->isAccountant()
            && AccountingFirmMember::query()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Whether the user owns at least one firm — the "create" counterpart of
     * isFirmOwner, which has no record to scope to.
     */
    protected function ownsAnyFirm(User $user): bool
    {
        return $user->isAccountant()
            && AccountingFirmMember::query()
                ->where('user_id', $user->getKey())
                ->where('is_owner', true)
                ->exists();
    }
}
