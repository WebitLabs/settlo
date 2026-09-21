<?php

namespace App\Policies;

use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Default-deny. Only an owner may manage bank accounts, only within a business
 * they own, and writes require an access-granting subscription (read-only lock
 * when a subscription lapses).
 */
class BankAccountPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, BankAccount $bankAccount): bool
    {
        return $user->isOwner()
            && BusinessEntity::whereKey($bankAccount->business_entity_id)
                ->where('owner_id', $user->getKey())
                ->exists();
    }

    public function create(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function update(User $user, BankAccount $bankAccount): bool
    {
        return $this->canWriteIn($user, $bankAccount->business_entity_id);
    }

    public function delete(User $user, BankAccount $bankAccount): bool
    {
        return $this->canWriteIn($user, $bankAccount->business_entity_id);
    }

    /**
     * Bulk deletion is offered only in a workspace the owner may write to; each
     * record is still checked against {@see self::delete()}.
     */
    public function deleteAny(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }
}
