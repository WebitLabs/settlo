<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Owns the Ask Settlo conversation flow: creating tenant-scoped conversations
 * and turning a user message into a persisted assistant reply. All ownership
 * columns are set server-side via forceFill — never mass-assigned from input.
 */
class AskSettloService
{
    public function __construct(
        private readonly ChatResponder $responder,
        private readonly ChatContextAssembler $assembler,
    ) {}

    /**
     * Start a new conversation owned by the given user within their business.
     * The title is optional and is auto-derived from the first user message.
     */
    public function startConversation(User $user, BusinessEntity $entity, ?string $title = null): AiConversation
    {
        $conversation = new AiConversation;

        $conversation->forceFill([
            'user_id' => $user->getKey(),
            'business_entity_id' => $entity->getKey(),
            'title' => $title,
        ])->save();

        return $conversation;
    }

    /**
     * Persist the user's message, generate an assistant reply from the full
     * history + freshly assembled context, persist it, auto-title the
     * conversation, and return the assistant message.
     */
    public function sendMessage(AiConversation $conversation, string $userContent): AiMessage
    {
        ['reply' => $reply, 'snapshot' => $snapshot] = $this->prepareReply($conversation, $userContent);

        return $this->persistAssistantMessage($conversation, $userContent, $reply, $snapshot);
    }

    /**
     * Persist the user's message, assemble fresh context and generate the reply
     * without persisting the assistant turn yet. Used by the streaming endpoint,
     * which streams {@see ChatReply::chunks()} before committing the message.
     *
     * @return array{reply: ChatReply, snapshot: array<string, mixed>}
     */
    public function prepareReply(AiConversation $conversation, string $userContent): array
    {
        $this->persistUserMessage($conversation, $userContent);

        $context = $this->assembler->assemble(
            $conversation->user()->firstOrFail(),
            $conversation->businessEntity()->firstOrFail(),
        );

        $reply = $this->responder->respond($this->history($conversation), $context->systemPrompt);

        return ['reply' => $reply, 'snapshot' => $context->snapshot];
    }

    /**
     * Commit a generated reply as the assistant message, auto-title the
     * conversation from the user's first turn, and bump its timestamp.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function persistAssistantMessage(AiConversation $conversation, string $userContent, ChatReply $reply, array $snapshot): AiMessage
    {
        $assistantMessage = new AiMessage;
        $assistantMessage->forceFill([
            'conversation_id' => $conversation->getKey(),
            'role' => 'assistant',
            'content' => $reply->content,
            'category' => null,
            'model_used' => $reply->model,
            'confidence' => $reply->confidence,
            'tokens_used' => $reply->tokensUsed,
            'processing_ms' => $reply->processingMs,
            'context_snapshot' => $snapshot,
        ])->save();

        $this->autoTitle($conversation, $userContent);
        $conversation->touch();

        return $assistantMessage;
    }

    private function persistUserMessage(AiConversation $conversation, string $userContent): void
    {
        $message = new AiMessage;
        $message->forceFill([
            'conversation_id' => $conversation->getKey(),
            'role' => 'user',
            'content' => $userContent,
        ])->save();
    }

    /**
     * The complete conversation history, oldest first, in provider shape.
     *
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private function history(AiConversation $conversation): array
    {
        return $conversation->messages()
            ->get(['role', 'content'])
            ->map(static fn (AiMessage $message): array => [
                'role' => $message->role,
                'content' => $message->content,
            ])
            ->all();
    }

    private function autoTitle(AiConversation $conversation, string $firstUserContent): void
    {
        if (filled($conversation->title)) {
            return;
        }

        $conversation->forceFill([
            'title' => Str::limit(trim($firstUserContent), 50, ''),
        ])->save();
    }
}
