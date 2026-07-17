<?php

namespace App\Policies;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;

/**
 * Default-deny. A message is reachable only through a conversation the user
 * owns and whose business entity they still own — the same owner-private
 * boundary enforced on {@see AiConversationPolicy}.
 */
class AiMessagePolicy
{
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
        return $user->isOwner() && $user->canWrite();
    }

    public function update(User $user, AiMessage $message): bool
    {
        return $this->owns($user, $message) && $user->canWrite();
    }

    public function delete(User $user, AiMessage $message): bool
    {
        return $this->owns($user, $message) && $user->canWrite();
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
