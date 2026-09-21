<?php

use Database\Seeders\ReferenceDataSeeder;

/**
 * BUG-03/04/10/15/31/38: the tester saw the browser's own grey validation
 * bubble instead of a styled message, and saw messages linger after correcting
 * the field. Neither is reachable from a Feature test: the first depends on the
 * rendered `novalidate` attribute, the second on when Livewire re-validates.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('renders the registration form with browser validation disabled', function () {
    visit('/app/register')
        ->assertSourceHas('novalidate')
        ->assertNoJavaScriptErrors();
});

it('clears a password error once the field is corrected', function () {
    $page = visit('/app/register');

    $page->fill('#form\\.password', 'short')
        ->click('#form\\.passwordConfirmation')
        ->assertSee('at least 8 characters');

    $page->fill('#form\\.password', 'a-much-longer-password')
        ->click('#form\\.passwordConfirmation')
        ->assertDontSee('at least 8 characters');
});

it('renders the client and bank-account forms with browser validation disabled', function () {
    [$owner, $entity] = workspaceOwner();
    $this->actingAs($owner);

    visit(workspaceUrl($entity, 'clients/create'))
        ->assertSourceHas('novalidate')
        ->assertNoJavaScriptErrors();

    // Modal forms render through a different Filament stack than page forms,
    // so the same default is asserted separately here.
    visit(workspaceUrl($entity, 'bank-accounts'))
        ->click('New bank account')
        ->wait(1)
        ->assertSee('IBAN')
        ->assertSourceHas('novalidate');
});
