<?php

namespace App\Services\Ai;

/**
 * A single assistant reply produced by a {@see ChatResponder}. Content is the
 * full assembled answer; chunks() yields it in pieces for server-sent-event
 * streaming (a non-streaming responder may yield the whole content once).
 */
final readonly class ChatReply
{
    /**
     * @param  list<string>  $contentChunks  Optional pre-split pieces for streaming.
     */
    public function __construct(
        public string $content,
        public ?float $confidence,
        public int $tokensUsed,
        public string $model,
        public int $processingMs,
        private array $contentChunks = [],
    ) {}

    /**
     * Yield the reply content in order. Falls back to a single whole-content
     * chunk when no explicit pieces were provided.
     *
     * @return iterable<int, string>
     */
    public function chunks(): iterable
    {
        if ($this->contentChunks !== []) {
            yield from $this->contentChunks;

            return;
        }

        yield $this->content;
    }
}
