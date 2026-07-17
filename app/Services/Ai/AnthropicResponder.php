<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ask Settlo responder backed by the Anthropic Messages API.
 *
 * Security: the API key is read from server config and sent only as the
 * x-api-key request header, never embedded in a URL, logged, or exposed to the
 * client. On failure only the HTTP status is logged/surfaced — the request and
 * response bodies (which carry the user's tax context) are never logged.
 */
class AnthropicResponder implements ChatResponder
{
    private const string API_BASE_URL = 'https://api.anthropic.com';

    private const string API_VERSION = '2023-06-01';

    /** The Messages API returns no confidence score, so we surface a stable default. */
    private const float DEFAULT_CONFIDENCE = 0.90;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    public function respond(array $messages, string $systemPrompt): ChatReply
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->http
                ->baseUrl(self::API_BASE_URL)
                ->timeout(60)
                ->connectTimeout(10)
                ->retry(2, 1000, throw: false)
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                ])
                ->post('/v1/messages', [
                    'model' => $this->model,
                    'max_tokens' => $this->maxTokens,
                    'system' => $systemPrompt,
                    'messages' => array_map(
                        static fn (array $message): array => [
                            'role' => $message['role'],
                            'content' => $message['content'],
                        ],
                        array_values($messages),
                    ),
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
        $content = data_get($body, 'content.0.text');

        if (! is_string($content) || trim($content) === '') {
            throw new AiException('The assistant returned an empty response.');
        }

        $inputTokens = (int) data_get($body, 'usage.input_tokens', 0);
        $outputTokens = (int) data_get($body, 'usage.output_tokens', 0);

        return new ChatReply(
            content: $content,
            confidence: self::DEFAULT_CONFIDENCE,
            tokensUsed: $inputTokens + $outputTokens,
            model: (string) (data_get($body, 'model') ?: $this->model),
            processingMs: $processingMs,
        );
    }

    private function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
