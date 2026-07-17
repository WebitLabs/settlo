<?php

use App\Models\AccountingFirm;
use App\Models\BusinessEntity;
use App\Models\User;
use Database\Seeders\CantonSeeder;

/**
 * Threat model: cross-panel-access-canaccesspanel (CRITICAL). Panel access is
 * default-deny — a user may only enter the panel matching their role, and only
 * while active. With multi-tenancy enabled, an allowed owner/accountant is
 * redirected to their tenant-scoped dashboard, so we follow redirects and
 * assert the request is never forbidden.
 */
it('enforces the role × panel access matrix', function (string $role, string $panel, bool $allowed) {
    $user = User::factory()->{$role}()->create();

    if ($allowed && $panel === 'app') {
        $this->seed(CantonSeeder::class);
        BusinessEntity::factory()->for($user, 'owner')->create();
    }

    if ($allowed && $panel === 'firm') {
        $firm = AccountingFirm::factory()->create();
        $user->firmMemberships()->create([
            'accounting_firm_id' => $firm->getKey(),
            'is_owner' => true,
            'joined_at' => now(),
        ]);
    }

    $response = $this->actingAs($user)->followingRedirects()->get("/{$panel}");

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

it('denies an owner access to a business entity they do not own', function () {
    $this->seed(CantonSeeder::class);
    $owner = User::factory()->owner()->create();
    $intruder = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->for($owner, 'owner')->create();

    // canAccessTenant() is false for the intruder, so Filament aborts 404 rather
    // than 403 — deliberately not revealing that the tenant exists (anti-enumeration).
    $this->actingAs($intruder)->get("/app/{$entity->getKey()}")->assertNotFound();
});
