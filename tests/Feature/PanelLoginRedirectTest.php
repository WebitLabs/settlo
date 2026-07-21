<?php

use App\Models\AccountingFirm;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Threat model: cross-panel-access-canaccesspanel. A stale intended URL
 * pointing at another panel must not hijack the post-login redirect.
 */
beforeEach(function () {
    $user = User::factory()->accountant()->create([
        'email' => 'maria@example.test',
        'password' => 'password',
    ]);

    $this->firm = AccountingFirm::factory()->create();
    $user->firmMemberships()->create([
        'accounting_firm_id' => $this->firm->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);

    Filament::setCurrentPanel(Filament::getPanel('firm'));
});

it('ignores an intended URL that points at another panel', function () {
    session(['url.intended' => url('/admin')]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'maria@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertRedirect(url("/firm/{$this->firm->getKey()}"));
});

it('honours an intended URL inside the panel that was signed in to', function () {
    session(['url.intended' => url('/firm/clients')]);

    Livewire::test(Login::class)
        ->fillForm(['email' => 'maria@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertRedirect(url('/firm/clients'));
});
