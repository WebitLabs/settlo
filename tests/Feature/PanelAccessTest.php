<?php

use App\Models\AccountingFirm;
use App\Models\BusinessEntity;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\CantonSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Livewire\Livewire;

/**
 * Threat model: cross-panel-access-canaccesspanel (CRITICAL). Panel access is
 * default-deny — a user may only enter the panel matching their role, and only
 * while active. With multi-tenancy enabled, an allowed owner/accountant is
 * redirected to their tenant-scoped dashboard, so we follow redirects and
 * assert the request is never forbidden.
 */
it('enforces the role × panel access matrix', function (string $role, string $panel, bool $allowed) {
    $user = User::factory()->{$role}()->create();

    if ($allowed && $panel === 'app/w') {
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
    'owner → workspace' => ['owner', 'app/w', true],
    'owner → firm' => ['owner', 'firm', false],
    'owner → admin' => ['owner', 'admin', false],
    'accountant → app' => ['accountant', 'app', false],
    'accountant → workspace' => ['accountant', 'app/w', false],
    'accountant → firm' => ['accountant', 'firm', true],
    'accountant → admin' => ['accountant', 'admin', false],
    'superadmin → app' => ['superadmin', 'app', false],
    'superadmin → workspace' => ['superadmin', 'app/w', false],
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
    $this->actingAs($intruder)->get("/app/w/{$entity->getKey()}")->assertNotFound();
});

describe('workspace panel (B1)', function () {
    beforeEach(function () {
        $this->seed(CantonSeeder::class);
    });

    it('sends guests to the personal login and back to the intended workspace afterwards', function () {
        $owner = User::factory()->owner()->create(['email' => 'owner@example.test', 'password' => 'password']);
        $entity = BusinessEntity::factory()->for($owner, 'owner')->create();
        $url = url("/app/w/{$entity->getKey()}/invoices");

        $this->get($url)->assertRedirect('/app/login');

        Filament::setCurrentPanel(Filament::getPanel('app'));

        Livewire::test(Login::class)
            ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
            ->call('authenticate')
            ->assertRedirect($url);
    });

    it('sends an owner without a business to "Set up a business"', function () {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/app/w')->assertRedirect('/app/businesses/new');
    });

    it('opens the personal dashboard for an owner without a business', function () {
        $owner = User::factory()->owner()->create();

        $this->actingAs($owner)->get('/app')->assertOk()->assertSee('No business yet');
    });

    it('lists the owner\'s businesses on the personal dashboard', function () {
        $owner = User::factory()->owner()->create();
        $entity = BusinessEntity::factory()->for($owner, 'owner')->create(['name' => 'Alpine Design']);
        BusinessEntity::factory()->create(['name' => 'Someone Else GmbH']);

        $this->actingAs($owner)->get('/app')
            ->assertOk()
            ->assertSee('Alpine Design')
            ->assertSee(url("/app/w/{$entity->getKey()}"))
            ->assertDontSee('Someone Else GmbH');
    });

    it('remembers the last opened workspace and returns to it', function () {
        $owner = User::factory()->owner()->create();
        $older = BusinessEntity::factory()->for($owner, 'owner')->create(['name' => 'A first', 'created_at' => now()->subDay()]);
        $newer = BusinessEntity::factory()->for($owner, 'owner')->create(['name' => 'B second']);
        Subscription::factory()->forEntity($newer)->create();

        $this->actingAs($owner)->get('/app/w')->assertRedirect("/app/w/{$older->getKey()}");

        $this->actingAs($owner)->get("/app/w/{$newer->getKey()}")->assertOk();

        expect($owner->fresh()->last_business_entity_id)->toBe($newer->getKey());

        $this->actingAs($owner->fresh())->get('/app/w')->assertRedirect("/app/w/{$newer->getKey()}");
    });

    it('asks an owner with an unverified email to verify it before opening a workspace', function () {
        $owner = User::factory()->owner()->unverified()->create();
        $entity = BusinessEntity::factory()->for($owner, 'owner')->create();

        $this->actingAs($owner)->get("/app/w/{$entity->getKey()}")
            ->assertRedirect('/app/email-verification/prompt');
    });

    it('signs out of a workspace back to the personal login', function () {
        $owner = User::factory()->owner()->create();
        BusinessEntity::factory()->for($owner, 'owner')->create();

        $this->actingAs($owner)->post('/app/w/logout')->assertRedirect();
        $this->assertGuest();
        $this->followingRedirects()->get('/app/w')->assertOk()->assertSee('Sign in');
    });

    it('links the workspace back to the personal area', function () {
        $owner = User::factory()->owner()->create();
        $entity = BusinessEntity::factory()->for($owner, 'owner')->create();
        Subscription::factory()->forEntity($entity)->create();

        $this->actingAs($owner)->get("/app/w/{$entity->getKey()}")
            ->assertOk()
            ->assertSee('All businesses')
            ->assertSee(url('/app/tax-profile'))
            ->assertSee(url('/app/profile'));
    });
});

it('renders the owner login and password reset pages at the wide simple-page width', function (string $path) {
    expect(Filament::getPanel('app')->getSimplePageMaxContentWidth())->toBe(Width::TwoExtraLarge);

    $this->get($path)
        ->assertSuccessful()
        ->assertSee('fi-width-2xl', escape: false);
})->with([
    'login' => '/app/login',
    'password reset request' => '/app/password-reset/request',
]);
