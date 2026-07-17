<?php

namespace App\Providers;

use App\Billing\DummyGateway;
use App\Billing\PaymentGateway;
use App\Services\Ai\AnthropicResponder;
use App\Services\Ai\ChatResponder;
use App\Services\Ai\FakeAskSettloResponder;
use App\Services\Extraction\FakeExtractor;
use App\Services\Extraction\GeminiExtractor;
use App\Services\Extraction\ReceiptExtractor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Payment provider is swappable via config; the POC ships the dummy.
        $this->app->bind(PaymentGateway::class, function () {
            return match (config('settlo.payment_gateway', 'dummy')) {
                default => new DummyGateway,
            };
        });

        // Receipt/invoice extraction uses Gemini when a key is configured,
        // and a deterministic fake otherwise (tests, local without a key).
        $this->app->bind(ReceiptExtractor::class, function ($app): ReceiptExtractor {
            $key = config('services.gemini.key');

            if (blank($key)) {
                return new FakeExtractor;
            }

            return new GeminiExtractor(
                http: $app->make(HttpFactory::class),
                apiKey: $key,
                model: config('services.gemini.model'),
                endpoint: config('services.gemini.endpoint'),
            );
        });

        // Ask Settlo chat uses the Anthropic Messages API when a key is
        // configured, and a deterministic fake otherwise (tests, local).
        $this->app->bind(ChatResponder::class, function ($app): ChatResponder {
            $key = config('settlo.anthropic.api_key');

            if (blank($key)) {
                return new FakeAskSettloResponder;
            }

            return new AnthropicResponder(
                http: $app->make(HttpFactory::class),
                apiKey: $key,
                model: config('settlo.anthropic.model'),
                maxTokens: (int) config('settlo.anthropic.max_tokens'),
            );
        });
    }

    public function boot(): void
    {
        // Surface N+1 queries during local development.
        Model::preventLazyLoading($this->app->environment('local'));
    }
}
