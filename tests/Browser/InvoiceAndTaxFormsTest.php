<?php

use Database\Seeders\ReferenceDataSeeder;

/**
 * BUG-17 (the invoice summary did not follow what was typed) and BUG-36 (the
 * Pillar 3a cap was applied silently on save, so the field kept showing an
 * impossible number). Both are live-field behaviours that only a real browser
 * exercises.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('updates the invoice summary as the line item is typed', function () {
    [$owner, $entity] = workspaceOwner();
    $this->actingAs($owner);

    visit(workspaceUrl($entity, 'invoices/create'))
        ->fill("input[id\$='.description']", 'Consulting')
        ->fill("input[id\$='.quantity']", '3')
        ->fill("input[id\$='.unit_price']", '100')
        // Leaving the field is what commits the value; the summary must follow
        // without saving the invoice.
        ->click('#form\\.reference')
        ->wait(1)
        ->assertSee('CHF 300')
        ->assertNoJavaScriptErrors();
});

it('clamps Pillar 3a to the legal maximum when the field is left', function () {
    [$owner] = workspaceOwner();
    $this->actingAs($owner);

    visit('/app/tax-profile')
        ->fill('#form\\.pillar3a_amount', '999999')
        ->click('#form\\.other_income')
        ->wait(1)
        ->assertValueIsNot('#form\\.pillar3a_amount', '999999')
        ->assertNoJavaScriptErrors();
});
