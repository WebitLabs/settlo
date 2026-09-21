<?php

namespace App\Http\Middleware;

use App\Models\BusinessEntity;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stores the workspace the owner last opened, so /app/w (and the default
 * tenant) brings them back to it.
 */
class RememberLastWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();
        $user = $request->user();

        if ($tenant instanceof BusinessEntity
            && $user instanceof User
            && $user->last_business_entity_id !== $tenant->getKey()) {
            $user->forceFill(['last_business_entity_id' => $tenant->getKey()])->saveQuietly();
        }

        return $next($request);
    }
}
