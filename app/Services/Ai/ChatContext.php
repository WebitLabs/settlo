<?php

namespace App\Services\Ai;

/**
 * The freshly assembled Settlo AI system prompt plus the machine-readable
 * snapshot of the live context values it was built from. The snapshot is
 * persisted on each assistant message for audit and reproducibility.
 */
final readonly class ChatContext
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(
        public string $systemPrompt,
        public array $snapshot,
    ) {}
}
