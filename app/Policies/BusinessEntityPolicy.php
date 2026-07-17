<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\BusinessEntity;
use App\Models\User;

/**
 * Default-deny authorization for business entities. Owners have full control of
 * the businesses they own (the app-panel tenant); accountants get read-only
 * visibility of the client businesses actively assigned to a firm they belong to
 * (the firm-panel client books). Anyone else is denied.
 */
class BusinessEntityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Owner
            || $user->role === UserRole::Accountant;
    }

    public function view(User $user, BusinessEntity $businessEntity): bool
    {
        if ($user->role === UserRole::Owner) {
            return $businessEntity->owner_id === $user->getKey();
        }

        if ($user->role === UserRole::Accountant) {
            return $businessEntity->accountantAssignments()
                ->whereNull('revoked_at')
                ->whereIn('accounting_firm_id', $user->accountingFirms()->select('accounting_firms.id'))
                ->exists();
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Owner;
    }

    public function update(User $user, BusinessEntity $businessEntity): bool
    {
        return $user->role === UserRole::Owner
            && $businessEntity->owner_id === $user->getKey();
    }

    public function delete(User $user, BusinessEntity $businessEntity): bool
    {
        return $user->role === UserRole::Owner
            && $businessEntity->owner_id === $user->getKey();
    }
}
