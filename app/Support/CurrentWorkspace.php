<?php

namespace App\Support;

use App\Models\BusinessEntity;
use Filament\Facades\Filament;

/**
 * The business workspace the current request works in: the Filament tenant
 * in the workspace panel, or the `{businessEntity}` route parameter of the
 * workspace-scoped HTTP endpoints (Ask Settlo). Ownership is checked by the
 * caller (panel tenancy / controller).
 */
class CurrentWorkspace
{
    public static function entity(): ?BusinessEntity
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof BusinessEntity) {
            return $tenant;
        }

        $routeEntity = request()->route('businessEntity');

        return $routeEntity instanceof BusinessEntity ? $routeEntity : null;
    }
}
