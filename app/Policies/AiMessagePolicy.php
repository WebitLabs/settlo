<?php

namespace App\Policies;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Default-deny. A message is reachable only through a conversation the user
 * owns and whose business entity they still own — the same owner-private
 * boundary enforced on {@see AiConversationPolicy}.
 */
class AiMessagePolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, AiMessage $message): bool
    {
        return $this->owns($user, $message);
    }

    public function create(User $user): bool
    {
        return $this->canCreateInCurrentWorkspace($user);
    }

    public function update(User $user, AiMessage $message): bool
    {
        return $this->owns($user, $message) && $this->canWriteIn($user, $this->entityId($message));
    }

    public function delete(User $user, AiMessage $message): bool
    {
        return $this->owns($user, $message) && $this->canWriteIn($user, $this->entityId($message));
    }

    private function entityId(AiMessage $message): ?string
    {
        return AiConversation::whereKey($message->conversation_id)->value('business_entity_id');
    }

    private function owns(User $user, AiMessage $message): bool
    {
        return $user->isOwner()
            && AiConversation::whereKey($message->conversation_id)
                ->where('user_id', $user->getKey())
                ->whereHas('businessEntity', fn ($query) => $query->where('owner_id', $user->getKey()))
                ->exists();
    }
}
