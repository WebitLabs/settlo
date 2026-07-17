<?php

namespace App\Policies;

use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\User;

/**
 * Default-deny. An owner manages expenses only within a business they own.
 * An accountant with an active assignment may view (read-only) — the write
 * paths stay owner-only. Writes require an access-granting subscription.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense) || $this->assignedAccountant($user, $expense);
    }

    public function create(User $user): bool
    {
        return $user->isOwner() && $user->canWrite();
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense) && $user->canWrite();
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense) && $user->canWrite();
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense) && $user->canWrite();
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense) && $user->canWrite();
    }

    private function owns(User $user, Expense $expense): bool
    {
        return $user->isOwner()
            && BusinessEntity::whereKey($expense->business_entity_id)
                ->where('owner_id', $user->getKey())
                ->exists();
    }

    private function assignedAccountant(User $user, Expense $expense): bool
    {
        return $user->isAccountant()
            && BusinessEntity::whereKey($expense->business_entity_id)
                ->whereHas('accountantAssignments', fn ($q) => $q
                    ->whereNull('revoked_at')
                    ->where('accountant_id', $user->getKey()))
                ->exists();
    }
}
