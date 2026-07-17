<?php

namespace App\Policies;

use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Default-deny. An owner manages expenses only within a business they own.
 * An accountant with an active assignment through a firm they are a member of
 * may view (read-only) — the write paths stay owner-only. Writes require an
 * access-granting subscription.
 */
class ExpensePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() || $user->isAccountant();
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->owns($user, $expense)
            || $this->assignedAccountant($user, $expense->business_entity_id);
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

    /**
     * An accountant may read a client's books only through an active
     * (non-revoked) assignment owned by a firm they are a member of.
     */
    private function assignedAccountant(User $user, string $businessEntityId): bool
    {
        return $user->isAccountant()
            && $user->accountingFirms()
                ->whereHas('activeAssignments', fn (Builder $query) => $query
                    ->where('business_entity_id', $businessEntityId))
                ->exists();
    }
}
