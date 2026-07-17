<?php

namespace App\Policies;

use App\Models\AiConversation;
use App\Models\BusinessEntity;
use App\Models\User;

/**
 * Default-deny. Ask Settlo conversations are strictly owner-private: only the
 * owner who created a conversation, and who still owns its business entity, may
 * see or act on it. Accountants never gain access through this surface.
 */
class AiConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, AiConversation $conversation): bool
    {
        return $this->owns($user, $conversation);
    }

    public function create(User $user): bool
    {
        return $user->isOwner() && $user->canWrite();
    }

    public function update(User $user, AiConversation $conversation): bool
    {
        return $this->owns($user, $conversation) && $user->canWrite();
    }

    public function delete(User $user, AiConversation $conversation): bool
    {
        return $this->owns($user, $conversation) && $user->canWrite();
    }

    public function restore(User $user, AiConversation $conversation): bool
    {
        return $this->owns($user, $conversation) && $user->canWrite();
    }

    public function forceDelete(User $user, AiConversation $conversation): bool
    {
        return $this->owns($user, $conversation) && $user->canWrite();
    }

    private function owns(User $user, AiConversation $conversation): bool
    {
        return $user->isOwner()
            && $conversation->user_id === $user->getKey()
            && BusinessEntity::whereKey($conversation->business_entity_id)
                ->where('owner_id', $user->getKey())
                ->exists();
    }
}
