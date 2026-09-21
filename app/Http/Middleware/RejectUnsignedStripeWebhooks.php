<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier only verifies webhook signatures when STRIPE_WEBHOOK_SECRET is set,
 * so without it anyone could post a forged "subscription active" event. Outside
 * local development and tests the Stripe webhook endpoint is therefore refused
 * until the secret is configured.
 */
class RejectUnsignedStripeWebhooks
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::isStripeWebhook($request) && ! self::acceptsUnsignedWebhooks()) {
            Log::critical('Stripe webhook refused: STRIPE_WEBHOOK_SECRET is not configured.');

            abort(Response::HTTP_FORBIDDEN, 'Stripe webhooks require a signing secret.');
        }

        return $next($request);
    }

    /**
     * Whether webhooks may be processed without a signature check.
     */
    public static function acceptsUnsignedWebhooks(): bool
    {
        return filled(config('cashier.webhook.secret')) || app()->environment('local', 'testing');
    }

    private static function isStripeWebhook(Request $request): bool
    {
        return $request->isMethod('POST')
            && $request->is(trim((string) config('cashier.path', 'stripe'), '/').'/webhook');
    }
}
