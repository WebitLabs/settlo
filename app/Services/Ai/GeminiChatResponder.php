<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ask Settlo responder backed by the Google Gemini generateContent API.
 *
 * Security: the API key is read from server config and sent only as the
 * x-goog-api-key request header, never embedded in a URL, logged, or exposed
 * to the client. On failure only the HTTP status is logged/surfaced — the
 * request and response bodies (which carry the user's tax context) are never
 * logged.
 */
class GeminiChatResponder implements ChatResponder
{
    /** Finish reasons that mean the provider refused to produce an answer. */
    private const array BLOCKED_FINISH_REASONS = ['SAFETY', 'RECITATION', 'PROHIBITED_CONTENT', 'BLOCKLIST'];

    /** Appended when the reply hit the output token ceiling. */
    private const string SHORTENED_NOTICE = "\n\n(This answer was shortened. Ask me to continue for the rest.)";

    /** The chat shows replies as plain text, so the model must not use markdown. */
    private const string PLAIN_TEXT_INSTRUCTION = "\n\nFormatting: the chat shows your answer as plain text. Do not use markdown: "
        .'no **bold**, _italics_, # headings, tables or code blocks. For lists, start each line with "- ".';

    /** Gemini returns no confidence score, so we surface a stable default. */
    private const float DEFAULT_CONFIDENCE = 0.90;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $endpoint,
    ) {}

    public function respond(array $messages, string $systemPrompt): ChatReply
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->http
                ->baseUrl($this->endpoint)
                ->timeout((int) config('services.gemini.chat_timeout', 60))
                ->connectTimeout(10)
                ->retry(2, 1000, throw: false)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->post("/models/{$this->model}:generateContent", [
                    'system_instruction' => [
                        'parts' => [['text' => $systemPrompt.self::PLAIN_TEXT_INSTRUCTION]],
                    ],
                    'contents' => array_map(
                        static fn (array $message): array => [
                            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                            'parts' => [['text' => $message['content']]],
                        ],
                        array_values($messages),
                    ),
                    'generationConfig' => $this->generationConfig(),
                ]);
        } catch (Throwable $exception) {
            throw new AiException('The assistant is currently unavailable.', previous: $exception);
        }

        if ($response->failed()) {
            Log::warning('Ask Settlo responder returned an error status.', ['status' => $response->status()]);

            throw new AiException("Assistant provider error (HTTP {$response->status()}).");
        }

        return $this->parse($response->json(), $this->elapsedMs($startedAt));
    }

    /**
     * Gemini counts "thinking" tokens against maxOutputTokens, so the ceiling must
     * leave room for both the reasoning and the visible answer.
     *
     * @return array{maxOutputTokens: int, thinkingConfig?: array{thinkingLevel: string}}
     */
    private function generationConfig(): array
    {
        $thinkingLevel = config('services.gemini.chat_thinking_level');

        return array_filter([
            'maxOutputTokens' => (int) config('services.gemini.chat_max_output_tokens', 8192),
            'thinkingConfig' => filled($thinkingLevel) ? ['thinkingLevel' => (string) $thinkingLevel] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function parse(?array $body, int $processingMs): ChatReply
    {
        // Newer Gemini models may interleave non-text parts (e.g. thought
        // signatures) — join every text-bearing part instead of assuming
        // the first part carries the answer.
        $parts = data_get($body, 'candidates.0.content.parts', []);
        $content = collect(is_array($parts) ? $parts : [])
            ->pluck('text')
            ->filter(fn ($piece): bool => is_string($piece))
            ->implode('');

        $finishReason = data_get($body, 'candidates.0.finishReason');

        if (trim($content) === '') {
            if (in_array($finishReason, self::BLOCKED_FINISH_REASONS, true)) {
                throw new AiException('The assistant could not answer this question.');
            }

            throw new AiException('The assistant returned an empty response.');
        }

        if ($finishReason === 'MAX_TOKENS') {
            Log::warning('Ask Settlo reply hit the output token limit.', [
                'thoughts' => data_get($body, 'usageMetadata.thoughtsTokenCount'),
                'visible' => data_get($body, 'usageMetadata.candidatesTokenCount'),
            ]);

            $content .= self::SHORTENED_NOTICE;
        }

        return new ChatReply(
            content: $content,
            confidence: self::DEFAULT_CONFIDENCE,
            tokensUsed: (int) data_get($body, 'usageMetadata.totalTokenCount', 0),
            model: $this->model,
            processingMs: $processingMs,
        );
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
