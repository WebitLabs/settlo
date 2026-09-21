<?php

namespace App\Providers;

use App\Billing\DummyGateway;
use App\Billing\PaymentGateway;
use App\Billing\SimulatedGateway;
use App\Billing\StripeGateway;
use App\Http\Controllers\RedirectToWorkspaceController;
use App\Http\Responses\PanelScopedLoginResponse;
use App\Models\StripeSubscription;
use App\Models\StripeSubscriptionItem;
use App\Services\Ai\ChatResponder;
use App\Services\Ai\FakeAskSettloResponder;
use App\Services\Ai\GeminiChatResponder;
use App\Services\Audit\ImpersonationService;
use App\Services\Extraction\FakeExtractor;
use App\Services\Extraction\GeminiExtractor;
use App\Services\Extraction\ReceiptExtractor;
use App\Services\Phone\LogPhoneVerifier;
use App\Services\Phone\PhoneVerifier;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Forms\Components\Field;
use Filament\Http\Controllers\RedirectToTenantController;
use Filament\Schemas\Components\Form;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Cashier\Cashier;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Post-login redirects must stay inside the panel that was signed
        // in to; a stale intended URL from another panel would 403.
        $this->app->bind(LoginResponse::class, PanelScopedLoginResponse::class);

        // An owner without a business who opens /app/w is sent to "Set up a
        // business" in the personal panel (the workspace has no registration page).
        $this->app->bind(RedirectToTenantController::class, RedirectToWorkspaceController::class);

        // Stripe (Cashier) when selected and a secret key is configured. The
        // simulated gateway is allowed in every environment, but only when it
        // is selected explicitly — never as a silent fallback. The dummy
        // gateway hands out plans for free, so it is only used where
        // DummyGateway::isAllowed() (local, tests, explicit non-production);
        // anywhere else a misconfiguration fails loudly.
        $this->app->bind(PaymentGateway::class, function ($app): PaymentGateway {
            $gateway = config('settlo.payment_gateway');

            if ($gateway === 'stripe' && filled(config('cashier.secret'))) {
                return $app->make(StripeGateway::class);
            }

            if ($gateway === 'simulated') {
                return new SimulatedGateway;
            }

            if (DummyGateway::isAllowed()) {
                return new DummyGateway;
            }

            throw new RuntimeException($gateway === 'stripe'
                ? 'SETTLO_PAYMENT_GATEWAY=stripe requires STRIPE_SECRET.'
                : "The payment gateway [{$gateway}] is not allowed in the [{$app->environment()}] environment. Use SETTLO_PAYMENT_GATEWAY=stripe with STRIPE_SECRET.");
        });

        // SMS one-time codes: only the development "log" driver exists so far.
        $this->app->bind(PhoneVerifier::class, fn ($app): PhoneVerifier => match (config('settlo.phone_verification.driver')) {
            'log' => $app->make(LogPhoneVerifier::class),
            default => throw new InvalidArgumentException('Unknown phone verification driver ['.config('settlo.phone_verification.driver').'].'),
        });

        // Receipt/invoice extraction uses Gemini when a key is configured,
        // and a deterministic fake otherwise — but only in local/testing, so a
        // production deploy can never serve fabricated scans as real ones.
        $this->app->bind(ReceiptExtractor::class, function ($app): ReceiptExtractor {
            $key = config('services.gemini.key');

            if (blank($key)) {
                self::requireLocalAiFallback($app->environment());

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
        // and a deterministic fake otherwise — local/testing only, for the
        // same reason as the extractor above.
        $this->app->bind(ChatResponder::class, function ($app): ChatResponder {
            $key = config('services.gemini.key');

            if (blank($key)) {
                self::requireLocalAiFallback($app->environment());

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

    /**
     * The deterministic AI/OCR stand-ins exist for local development and the
     * test suite only. Anywhere else a missing GEMINI_API_KEY is a
     * misconfiguration that must fail loudly instead of quietly serving canned
     * answers and canned receipt scans that look real.
     */
    public static function requireLocalAiFallback(string $environment): void
    {
        if (in_array($environment, ['local', 'testing'], true)) {
            return;
        }

        throw new RuntimeException("GEMINI_API_KEY is required in the [{$environment}] environment: without it the app would serve simulated AI answers and simulated receipt scans.");
    }

    public function boot(): void
    {
        // Refuse to serve requests at all without a Gemini key outside
        // local/testing — a deploy must not come up half-real. Console stays
        // usable so migrations and seeding can still fix a broken deploy.
        if (! $this->app->runningInConsole()) {
            if (blank(config('services.gemini.key'))) {
                self::requireLocalAiFallback($this->app->environment());
            }
        }

        // Cashier's tables are renamed so they don't collide with the domain
        // (per-workspace) `subscriptions` table.
        Cashier::useSubscriptionModel(StripeSubscription::class);
        Cashier::useSubscriptionItemModel(StripeSubscriptionItem::class);

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

        // The native "×" clear button of a search input fires a "search" event but
        // not always an "input" event, so Livewire would never see the cleared
        // table search. Re-dispatch it as "input" (BUG-42).
        FilamentView::registerRenderHook(
            PanelsRenderHook::SCRIPTS_AFTER,
            fn (): string => <<<'HTML'
                <script>
                    document.addEventListener('search', (event) => {
                        if (event.target.matches('.fi-ta-search-field input[type=search]')) {
                            event.target.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    }, true);
                </script>
                HTML,
        );

        $this->configureFilamentForms();
    }

    /**
     * Server-side (Livewire) validation is the single source of truth: native browser
     * tooltips would otherwise block the request and hide Filament's styled messages.
     * Validation messages keep leading acronyms ("IBAN", "UID", "AHV") capitalised,
     * where Filament would lower-case the first letter ("iBAN").
     */
    private function configureFilamentForms(): void
    {
        Form::configureUsing(function (Form $form): void {
            $form->extraAttributes(['novalidate' => true], merge: true);
        });

        Field::configureUsing(function (Field $field): void {
            $field->validationAttribute(function (Field $component): ?string {
                $label = $component->getLabel();

                if (blank($label) || $label instanceof Htmlable) {
                    return null;
                }

                $label = (string) $label;

                return preg_match('/^\p{Lu}{2,}/u', $label) === 1 ? $label : Str::lcfirst($label);
            });
        });
    }
}
