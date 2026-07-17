<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on a per-business private channel whenever a receipt upload changes
 * processing state, so the UI can show/hide the live "reading receipt…" loader.
 * Carries only scalar identifiers — never the full model — to keep the payload
 * minimal and free of sensitive fields.
 */
class ExpenseProcessingUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $businessEntityId,
        public string $expenseId,
        public string $processingStatus,
        public ?string $vendor = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("business.{$this->businessEntityId}");
    }

    public function broadcastAs(): string
    {
        return 'expense.processing';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'expense_id' => $this->expenseId,
            'processing_status' => $this->processingStatus,
            'vendor' => $this->vendor,
        ];
    }
}
