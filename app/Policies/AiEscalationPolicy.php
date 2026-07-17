<?php

namespace App\Policies;

use App\Models\AccountantAssignment;
use App\Models\AiEscalation;
use App\Models\BusinessEntity;
use App\Models\User;

/**
 * Default-deny. Owners may view and resolve escalations on a business they still
 * own; only an accountant with an active (non-revoked) assignment to that same
 * business may answer one. Every check re-derives the owning business entity
 * from the conversation so a crafted id can never cross the tenant boundary.
 */
class AiEscalationPolicy
{
    public function view(User $user, AiEscalation $escalation): bool
    {
        return $this->ownsEntity($user, $escalation);
    }

    public function resolve(User $user, AiEscalation $escalation): bool
    {
        return $this->ownsEntity($user, $escalation) && $user->canWrite();
    }

    public function answer(User $user, AiEscalation $escalation): bool
    {
        if (! $user->isAccountant()) {
            return false;
        }

        $entityId = $this->entityId($escalation);

        if ($entityId === null) {
            return false;
        }

        return AccountantAssignment::query()
            ->where('business_entity_id', $entityId)
            ->where('accountant_id', $user->getKey())
            ->whereNull('revoked_at')
            ->exists();
    }

    private function ownsEntity(User $user, AiEscalation $escalation): bool
    {
        if (! $user->isOwner()) {
            return false;
        }

        $entityId = $this->entityId($escalation);

        return $entityId !== null
            && BusinessEntity::whereKey($entityId)
                ->where('owner_id', $user->getKey())
                ->exists();
    }

    private function entityId(AiEscalation $escalation): ?string
    {
        return $escalation->conversation()->value('business_entity_id');
    }
}
