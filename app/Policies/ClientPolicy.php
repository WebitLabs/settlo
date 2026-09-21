<?php

namespace App\Policies;

use App\Models\BusinessEntity;
use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Default-deny. Only an owner may manage clients, only within a business they
 * own, and writes require an access-granting subscription (read-only lock when
 * a subscription lapses).
 */
class ClientPolicy
{
    use ChecksWorkspaceAccess;

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
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->canWriteIn($user, $client->business_entity_id);
    }

    public function delete(User $user, Client $client): bool
    {
        return $this->canWriteIn($user, $client->business_entity_id);
    }

    public function restore(User $user, Client $client): bool
    {
        return $this->canWriteIn($user, $client->business_entity_id);
    }

    /**
     * A client with invoices (even trashed ones) can never be deleted
     * permanently: invoices reference it and keep showing its name.
     */
    public function forceDelete(User $user, Client $client): bool
    {
        return $this->canWriteIn($user, $client->business_entity_id)
            && ! $client->invoices()->withTrashed()->exists();
    }

    private function owns(User $user, Client $client): bool
    {
        return $user->isOwner()
            && BusinessEntity::whereKey($client->business_entity_id)
                ->where('owner_id', $user->getKey())
                ->exists();
    }
}
