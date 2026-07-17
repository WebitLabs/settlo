<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on a per-business private channel whenever an accountant escalation
 * changes state (created pending, answered, resolved), so the chat UI can flip
 * the escalation card live. Carries only scalar identifiers — never the model,
 * never the accountant answer body — to keep the payload minimal and safe.
 */
class AiEscalationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $businessEntityId,
        public string $escalationId,
        public string $conversationId,
        public string $status,
        public ?string $answeredAt = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("business.{$this->businessEntityId}");
    }

    public function broadcastAs(): string
    {
        return 'escalation.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'escalation_id' => $this->escalationId,
            'conversation_id' => $this->conversationId,
            'status' => $this->status,
            'answered_at' => $this->answeredAt,
        ];
    }
}
