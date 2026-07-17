<?php

namespace App\Policies;

use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\User;

/**
 * Default-deny. Only an owner may manage clients, only within a business they
 * own, and writes require an access-granting subscription (read-only lock when
 * a subscription lapses).
 */
class ClientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Client $client): bool
    {
        return $this->owns($user, $client);
    }

    public function create(User $user): bool
    {
        return $user->isOwner() && $user->canWrite();
    }

    public function update(User $user, Client $client): bool
    {
        return $this->owns($user, $client) && $user->canWrite();
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->owns($user, $client) && $user->canWrite();
    }

    public function restore(User $user, Client $client): bool
    {
        return $this->owns($user, $client) && $user->canWrite();
    }

    public function forceDelete(User $user, Client $client): bool
    {
        return $this->owns($user, $client) && $user->canWrite();
    }

    private function owns(User $user, Client $client): bool
    {
        return $user->isOwner()
            && BusinessEntity::whereKey($client->business_entity_id)
                ->where('owner_id', $user->getKey())
                ->exists();
    }
}
