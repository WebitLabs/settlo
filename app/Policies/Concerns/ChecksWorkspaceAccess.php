<?php

namespace App\Policies\Concerns;

use App\Models\BusinessEntity;
use App\Models\User;
use App\Support\CurrentWorkspace;

/**
 * Write access is per business workspace: the workspace's own subscription
 * decides whether records in it may be created or changed.
 */
trait ChecksWorkspaceAccess
{
    /**
     * The business with this id when the user is an owner who owns it.
     */
    protected function ownedEntity(User $user, ?string $businessEntityId): ?BusinessEntity
    {
        if (! $user->isOwner() || $businessEntityId === null) {
            return null;
        }

        return BusinessEntity::query()
            ->whereKey($businessEntityId)
            ->where('owner_id', $user->getKey())
            ->with('subscription')
            ->first();
    }

    /**
     * Whether the owner may write to a record of the given business.
     */
    protected function canWriteIn(User $user, ?string $businessEntityId): bool
    {
        return $this->ownedEntity($user, $businessEntityId)?->canWrite() ?? false;
    }

    /**
     * Whether the owner may create records in the current workspace.
     */
    protected function canCreateInCurrentWorkspace(User $user): bool
    {
        $entity = CurrentWorkspace::entity();

        return $user->isOwner()
            && $entity !== null
            && $entity->owner_id === $user->getKey()
            && $entity->canWrite();
    }
}
