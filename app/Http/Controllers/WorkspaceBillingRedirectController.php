<?php

namespace App\Http\Controllers;

use App\Filament\Personal\Pages\Billing;
use App\Models\BusinessEntity;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

/**
 * The workspace panel's tenant billing route (/app/w/{uuid}/billing): billing
 * lives in the personal area, pre-selecting this workspace.
 */
class WorkspaceBillingRedirectController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $tenant = Filament::getTenant();

        return redirect()->to(Billing::getUrl(
            $tenant instanceof BusinessEntity ? ['workspace' => $tenant->getKey()] : [],
            panel: 'app',
        ));
    }
}
