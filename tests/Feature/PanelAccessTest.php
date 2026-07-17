<?php

use App\Models\User;

/**
 * Threat model: cross-panel-access-canaccesspanel (CRITICAL). Panel access is
 * default-deny — a user may only enter the panel matching their role, and only
 * while active.
 */
it('enforces the role × panel access matrix', function (string $role, string $panel, bool $allowed) {
    $user = User::factory()->{$role}()->create();

    $response = $this->actingAs($user)->get("/{$panel}");

    if ($allowed) {
        $response->assertSuccessful();
    } else {
        $response->assertForbidden();
    }
})->with([
    'owner → app' => ['owner', 'app', true],
    'owner → firm' => ['owner', 'firm', false],
    'owner → admin' => ['owner', 'admin', false],
    'accountant → app' => ['accountant', 'app', false],
    'accountant → firm' => ['accountant', 'firm', true],
    'accountant → admin' => ['accountant', 'admin', false],
    'superadmin → app' => ['superadmin', 'app', false],
    'superadmin → firm' => ['superadmin', 'firm', false],
    'superadmin → admin' => ['superadmin', 'admin', true],
]);

it('locks out a suspended user from their own panel', function () {
    $user = User::factory()->owner()->suspended()->create();

    $this->actingAs($user)->get('/app')->assertForbidden();
});

it('redirects guests to the panel login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});
