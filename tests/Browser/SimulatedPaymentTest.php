<?php

use App\Enums\SubscriptionStatus;
use Database\Seeders\ReferenceDataSeeder;

/**
 * Payments are simulated for now: pressing pay must activate the workspace in
 * the page, with no redirect to a payment provider, and the screen must say
 * plainly that no card is charged. A Feature test proves the state change; only
 * a browser proves the owner never leaves the page and reads the disclosure.
 */
beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    config(['settlo.payment_gateway' => 'simulated']);
});

it('says the payment is simulated and keeps the owner on the billing page', function () {
    [$owner, $entity] = workspaceOwner();
    $entity->subscription->forceFill(['status' => SubscriptionStatus::Incomplete])->save();
    $this->actingAs($owner);

    $page = visit('/app/billing');

    $page->assertSee('Choose plan')
        ->click('Choose plan')
        ->wait(1)
        ->assertSee('no card is charged')
        ->assertSee('Pay now (simulated)')
        ->click('text=Pay now')
        ->wait(2)
        // No provider redirect: still on the billing page, now active.
        ->assertPathIs('/app/billing')
        ->assertSee('Active')
        ->assertNoJavaScriptErrors();

    expect($entity->subscription->refresh()->status)->toBe(SubscriptionStatus::Active)
        ->and($entity->subscription->payments()->count())->toBe(1);
});
