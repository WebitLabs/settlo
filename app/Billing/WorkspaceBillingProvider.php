<?php

namespace App\Billing;

use App\Http\Controllers\WorkspaceBillingRedirectController;
use App\Http\Middleware\EnsureWorkspaceSubscription;
use Filament\Billing\Providers\Contracts\BillingProvider;

/**
 * Filament tenant billing for the workspace panel: the "Billing" tenant menu
 * entry leads to the personal Billing page, and every workspace page requires
 * a started (non-incomplete) subscription.
 */
class WorkspaceBillingProvider implements BillingProvider
{
    public function getRouteAction(): string
    {
        return WorkspaceBillingRedirectController::class;
    }

    public function getSubscribedMiddleware(): string
    {
        return EnsureWorkspaceSubscription::class;
    }
}
