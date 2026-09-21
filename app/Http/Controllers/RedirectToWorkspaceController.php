<?php

namespace App\Http\Controllers;

use App\Filament\Personal\Pages\SetUpBusiness;
use Filament\Http\Controllers\RedirectToTenantController;
use Filament\Panel;
use Illuminate\Http\RedirectResponse;

/**
 * Handles a tenant panel's root URL. An owner without any business who opens
 * /app/w is sent to the personal "Set up a business" page, because the
 * workspace panel has no tenant registration page of its own.
 */
class RedirectToWorkspaceController extends RedirectToTenantController
{
    protected function redirectToTenantRegistration(Panel $panel): RedirectResponse
    {
        if ($panel->getId() === 'workspace') {
            return redirect(SetUpBusiness::getUrl(panel: 'app'));
        }

        return parent::redirectToTenantRegistration($panel);
    }
}
