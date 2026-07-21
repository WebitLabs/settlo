<?php

namespace App\Providers;

use App\Billing\DummyGateway;
use App\Billing\PaymentGateway;
use App\Http\Responses\PanelScopedLoginResponse;
use App\Services\Ai\ChatResponder;
use App\Services\Ai\FakeAskSettloResponder;
use App\Services\Ai\GeminiChatResponder;
use App\Services\Audit\ImpersonationService;
use App\Services\Extraction\FakeExtractor;
use App\Services\Extraction\GeminiExtractor;
use App\Services\Extraction\ReceiptExtractor;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Post-login redirects must stay inside the panel that was signed
        // in to; a stale intended URL from another panel would 403.
        $this->app->bind(LoginResponse::class, PanelScopedLoginResponse::class);

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

        // Ask Settlo chat uses the Google Gemini API when a key is configured,
        // and a deterministic fake otherwise (tests, local).
        $this->app->bind(ChatResponder::class, function ($app): ChatResponder {
            $key = config('services.gemini.key');

            if (blank($key)) {
                return new FakeAskSettloResponder;
            }

            return new GeminiChatResponder(
                http: $app->make(HttpFactory::class),
                apiKey: $key,
                model: config('services.gemini.model'),
                endpoint: config('services.gemini.endpoint'),
            );
        });
    }

    public function boot(): void
    {
        // Surface N+1 queries during local development.
        Model::preventLazyLoading($this->app->environment('local'));

        // Bound Ask Settlo AI chat by authenticated user so a scripted loop of
        // stream/message turns cannot run up unbounded third-party model cost or
        // exhaust provider rate limits. Falls back to IP for unauthenticated hits.
        RateLimiter::for('ask-settlo', function (Request $request): Limit {
            $perMinute = max(1, (int) config('settlo.ask_settlo_rate_limit', 30));

            return Limit::perMinute($perMinute)
                ->by((string) ($request->user()?->getKey() ?? $request->ip()));
        });

        // A global amber banner is shown across every panel whenever a
        // superadmin is impersonating another user, with a one-click stop.
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            fn (): string => app(ImpersonationService::class)->isImpersonating()
                ? view('impersonation-banner')->render()
                : '',
        );
    }
}
