<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

/**
 * Keeps the post-login redirect inside the panel the user signed in to.
 *
 * Threat model: cross-panel-access-canaccesspanel. The default Filament
 * response honours the session's intended URL even when it points at a
 * different panel (e.g. visiting /admin as a guest, then signing in to
 * /firm), which lands the user on a 403 instead of their dashboard.
 */
class PanelScopedLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $panelBaseUrl = url(Filament::getCurrentPanel()->getPath());
        $intended = session()->pull('url.intended');

        if (is_string($intended) && $this->isWithinPanel($intended, $panelBaseUrl)) {
            return redirect()->to($intended);
        }

        return redirect()->to(Filament::getUrl());
    }

    private function isWithinPanel(string $intended, string $panelBaseUrl): bool
    {
        return $intended === $panelBaseUrl
            || str_starts_with($intended, $panelBaseUrl.'/')
            || str_starts_with($intended, $panelBaseUrl.'?');
    }
}
