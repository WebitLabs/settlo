<?php

use App\Models\BusinessEntity;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function (User $user, string $id) {
    return $user->getKey() === $id;
});

/**
 * Per-business real-time channel. Only the business owner or an accountant with
 * an active (non-revoked) assignment may subscribe — the same trust boundary as
 * data access, enforced for live updates too.
 */
Broadcast::channel('business.{businessEntityId}', function (User $user, string $businessEntityId) {
    $entity = BusinessEntity::find($businessEntityId);

    if ($entity === null) {
        return false;
    }

    if ($user->isOwner()) {
        return $entity->owner_id === $user->getKey();
    }

    if ($user->isAccountant()) {
        return $entity->accountantAssignments()
            ->whereNull('revoked_at')
            ->where('accountant_id', $user->getKey())
            ->exists();
    }

    return false;
});
