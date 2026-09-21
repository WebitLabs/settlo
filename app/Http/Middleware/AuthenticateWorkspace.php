<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;

/**
 * Authenticates the workspace panel. The workspace has no login page of its
 * own, so guests are sent to the personal panel's login; the intended
 * /app/w/... URL is honoured after signing in (PanelScopedLoginResponse).
 */
class AuthenticateWorkspace extends Authenticate
{
    protected function redirectTo($request): ?string
    {
        return Filament::getPanel('app')->getLoginUrl();
    }
}
