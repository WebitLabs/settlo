<?php

namespace App\Services\Ai;

/**
 * Deterministic Ask Settlo responder used when no Anthropic key is configured
 * (tests, local development). Returns a canned Swiss-tax answer and streams it
 * as three chunks so the SSE path can be exercised without a live provider.
 */
class FakeAskSettloResponder implements ChatResponder
{
    /** @var list<string> */
    private const array REPLY_CHUNKS = [
        "As a Swiss sole proprietor, VAT (MWST/TVA) registration becomes mandatory once your worldwide turnover reaches CHF 100'000 within a 12-month period. ",
        'Below that threshold registration is voluntary: it lets you reclaim input VAT but adds quarterly reporting obligations. ',
        "Keep tracking your year-to-date revenue against the CHF 100'000 line so you can register with the ESTV in time and avoid retroactive VAT liability.",
    ];

    public function respond(array $messages, string $systemPrompt): ChatReply
    {
        return new ChatReply(
            content: implode('', self::REPLY_CHUNKS),
            confidence: 0.94,
            tokensUsed: 128,
            model: (string) config('settlo.anthropic.model', 'claude-sonnet-4-20250514'),
            processingMs: 5,
            contentChunks: self::REPLY_CHUNKS,
        );
    }
}
