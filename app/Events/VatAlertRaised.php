<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on a per-business private channel when the VAT registration alert
 * level rises into a warning/critical/mandatory band, so the dashboard can flip
 * its VAT card live. Carries only scalar identifiers and the new level — never
 * the model — to keep the payload minimal and safe.
 */
class VatAlertRaised implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $businessEntityId,
        public string $level,
        public float $thresholdPct,
        public ?string $crossingDate = null,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("business.{$this->businessEntityId}");
    }

    public function broadcastAs(): string
    {
        return 'vat.alert';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'level' => $this->level,
            'threshold_pct' => $this->thresholdPct,
            'crossing_date' => $this->crossingDate,
        ];
    }
}
