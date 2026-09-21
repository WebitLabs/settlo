<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RejectUnsignedStripeWebhooks;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        // Stripe webhooks are verified by their signature, not a CSRF token.
        $middleware->preventRequestForgery(except: ['stripe/*']);

        // ...and are refused outright when no signing secret is configured.
        $middleware->prepend(RejectUnsignedStripeWebhooks::class);

        // Baseline browser security headers on every response. Registered
        // globally rather than on the web group, because each Filament panel
        // declares its own middleware stack and would otherwise be uncovered.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
