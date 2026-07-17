<?php

namespace App\Services\Ai;

interface ChatResponder
{
    /**
     * Produce an assistant reply for the given conversation history.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages  Full history, oldest first, ending on the user turn.
     * @param  string  $systemPrompt  The freshly assembled Settlo AI system prompt.
     *
     * @throws AiException When the provider is unreachable or returns an unusable response.
     */
    public function respond(array $messages, string $systemPrompt): ChatReply;
}
