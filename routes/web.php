<?php

use App\Http\Controllers\AskSettlo\AskSettloController;
use App\Http\Controllers\CronCommandController;
use App\Http\Controllers\DummyCheckoutController;
use App\Http\Controllers\ExpenseReceiptController;
use App\Http\Controllers\FirmInvitationController;
use App\Http\Controllers\ImpersonationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome');
});

/*
 * Ends an admin impersonation session from the global banner. Available while
 * authenticated as the impersonated user; the service restores the original
 * superadmin and returns them to the admin panel.
 */
Route::middleware(['auth', 'throttle:30,1'])
    ->post('/impersonation/stop', [ImpersonationController::class, 'stop'])
    ->name('impersonation.stop');

/*
 * HTTP-triggerable scheduled commands for serverless deploys where no
 * schedule:run daemon and no queue worker exist. An external cron pinger (the
 * `crons` block of vercel.json) hits these with the CRON_SECRET bearer token
 * or the ?token= fallback; the controller whitelists the runnable commands and
 * refuses everything when no secret is configured. Besides the four lifecycle
 * commands this covers `drain-queue` (works queued jobs until empty) and the
 * idempotent `deploy` step (migrations + reference data).
 */
Route::middleware('throttle:30,1')
    ->get('/cron/{command}', CronCommandController::class)
    ->whereIn('command', array_keys(CronCommandController::COMMANDS))
    ->name('cron.run');

/*
 * Local checkout of the dummy payment gateway (the controller 404s when Stripe
 * is bound). Signed, owner-checked.
 */
Route::middleware(['auth', 'signed'])
    ->get('/billing/dummy-checkout/{subscription}', DummyCheckoutController::class)
    ->name('billing.dummy-checkout');

/*
 * Authorised download of a private receipt file (policy-checked in the
 * controller, which also refuses traversal and cross-tenant paths). Throttled
 * so an authenticated account cannot enumerate or hammer the private disk.
 */
Route::middleware(['auth', 'throttle:60,1'])
    ->get('/receipts/{expense}', ExpenseReceiptController::class)
    ->name('receipts.show');

/*
 * A client accepting a firm's invitation. The token is matched by hash and the
 * signed-in owner's email must match the invitation; the controller re-derives
 * the boundary and never trusts the URL. Throttled per authenticated client so
 * the single-use tokens cannot be brute-forced.
 */
Route::middleware(['auth', 'throttle:20,1'])->group(function () {
    Route::get('/firm-invitations/{token}', [FirmInvitationController::class, 'show'])
        ->name('firm-invitations.accept');
    Route::post('/firm-invitations/{token}', [FirmInvitationController::class, 'store'])
        ->name('firm-invitations.store');
});

/*
 * Ask Settlo AI chat. Every route is tenant-scoped by the {businessEntity} owner
 * and re-derives the boundary in the controller — the panel is never trusted to
 * have done it. The chat surface is a full Inertia page outside the Filament panel.
 * Every route is throttled per authenticated user (the 'ask-settlo' limiter) so
 * the live-model stream/message turns can't be looped into a runaway cost/DoS.
 * Like the panels, it needs a verified email; suspended users are rejected in
 * the controller.
 */
Route::middleware(['auth', 'verified:filament.app.auth.email-verification.prompt', 'throttle:ask-settlo'])
    ->prefix('ask-settlo/{businessEntity}')
    ->name('ask-settlo.')
    ->group(function () {
        Route::get('/', [AskSettloController::class, 'index'])->name('index');
        Route::get('/bootstrap', [AskSettloController::class, 'bootstrap'])->name('bootstrap');
        Route::post('/conversations', [AskSettloController::class, 'storeConversation'])->name('conversations.store');
        Route::get('/conversations/{conversation}', [AskSettloController::class, 'showConversation'])->name('conversations.show');
        Route::post('/conversations/{conversation}/messages', [AskSettloController::class, 'storeMessage'])->name('messages.store');
        Route::post('/conversations/{conversation}/stream', [AskSettloController::class, 'stream'])->name('stream');
        Route::post('/messages/{message}/escalate', [AskSettloController::class, 'escalate'])->name('escalate');
        Route::post('/escalations/{escalation}/resolve', [AskSettloController::class, 'resolve'])->name('escalations.resolve');
    });
