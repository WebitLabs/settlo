<?php

use App\Http\Controllers\AskSettlo\AskSettloController;
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
Route::middleware('auth')
    ->post('/impersonation/stop', [ImpersonationController::class, 'stop'])
    ->name('impersonation.stop');

// Authorised download of a private receipt file (policy-checked in the controller).
Route::middleware('auth')
    ->get('/receipts/{expense}', ExpenseReceiptController::class)
    ->name('receipts.show');

/*
 * A client accepting a firm's invitation. The token is matched by hash and the
 * signed-in owner's email must match the invitation; the controller re-derives
 * the boundary and never trusts the URL.
 */
Route::middleware('auth')->group(function () {
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
 */
Route::middleware(['auth', 'throttle:ask-settlo'])
    ->prefix('ask-settlo/{businessEntity}')
    ->name('ask-settlo.')
    ->group(function () {
        Route::get('/', [AskSettloController::class, 'index'])->name('index');
        Route::post('/conversations', [AskSettloController::class, 'storeConversation'])->name('conversations.store');
        Route::get('/conversations/{conversation}', [AskSettloController::class, 'showConversation'])->name('conversations.show');
        Route::post('/conversations/{conversation}/messages', [AskSettloController::class, 'storeMessage'])->name('messages.store');
        Route::post('/conversations/{conversation}/stream', [AskSettloController::class, 'stream'])->name('stream');
        Route::post('/messages/{message}/escalate', [AskSettloController::class, 'escalate'])->name('escalate');
        Route::post('/escalations/{escalation}/resolve', [AskSettloController::class, 'resolve'])->name('escalations.resolve');
    });
