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
    /** Ceiling on generated tokens per reply. */
    private const int MAX_OUTPUT_TOKENS = 1024;

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
                ->timeout(60)
                ->connectTimeout(10)
                ->retry(2, 1000, throw: false)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->post("/models/{$this->model}:generateContent", [
                    'system_instruction' => [
                        'parts' => [['text' => $systemPrompt]],
                    ],
                    'contents' => array_map(
                        static fn (array $message): array => [
                            'role' => $message['role'] === 'assistant' ? 'model' : 'user',
                            'parts' => [['text' => $message['content']]],
                        ],
                        array_values($messages),
                    ),
                    'generationConfig' => [
                        'maxOutputTokens' => self::MAX_OUTPUT_TOKENS,
                    ],
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
     * @param  array<string, mixed>|null  $body
     */
    private function parse(?array $body, int $processingMs): ChatReply
    {
        $content = data_get($body, 'candidates.0.content.parts.0.text');

        if (! is_string($content) || trim($content) === '') {
            throw new AiException('The assistant returned an empty response.');
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
