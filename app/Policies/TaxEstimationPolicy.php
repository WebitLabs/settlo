<?php

namespace App\Policies;

use App\Models\BusinessEntity;
use App\Models\TaxEstimation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Default-deny. Tax estimations are immutable snapshots produced by the engine,
 * so there is no create/update/delete path. An owner may read the estimations of
 * a business they own; an accountant may read them only through an active
 * (non-revoked) assignment owned by a firm they are a member of.
 */
class TaxEstimationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() || $user->isAccountant();
    }

    public function view(User $user, TaxEstimation $taxEstimation): bool
    {
        return $this->owns($user, $taxEstimation->business_entity_id)
            || $this->assignedAccountant($user, $taxEstimation->business_entity_id);
    }

    private function owns(User $user, string $businessEntityId): bool
    {
        return $user->isOwner()
            && BusinessEntity::whereKey($businessEntityId)
                ->where('owner_id', $user->getKey())
                ->exists();
    }

    /**
     * An accountant may read a client's tax data only through an active
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
