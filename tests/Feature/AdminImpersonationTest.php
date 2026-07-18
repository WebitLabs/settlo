<?php

use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\BusinessEntity;
use App\Models\User;
use App\Services\Audit\ImpersonationService;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->admin = User::factory()->superadmin()->create();
});

it('starts impersonation, switches the auth user, and audits with the impersonator', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($this->admin);

    app(ImpersonationService::class)->start($owner);

    expect(Auth::id())->toBe($owner->getKey())
        ->and(session(ImpersonationService::SESSION_KEY))->toBe($this->admin->getKey());

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'impersonation.started',
        'subject_id' => (string) $owner->getKey(),
        'impersonator_id' => $this->admin->getKey(),
    ]);
});

it('keeps the impersonated session authenticated across panel requests', function () {
    $owner = User::factory()->owner()->create();
    BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();

    $this->actingAs($this->admin);

    $service = app(ImpersonationService::class);
    $service->start($owner);

    // AuthenticateSession compares this stored hash against the current user on
    // every panel request — a stale hash bounces the target to the login screen.
    expect(session('password_hash_web'))->toBe($owner->getAuthPassword());

    $this->followingRedirects()->get('/app')->assertSuccessful();

    $service->stop();

    expect(session('password_hash_web'))->toBe($this->admin->getAuthPassword());
    $this->followingRedirects()->get('/admin')->assertSuccessful();
});

it('rejects impersonating another superadmin', function () {
    $otherAdmin = User::factory()->superadmin()->create();

    $this->actingAs($this->admin);

    expect(fn () => app(ImpersonationService::class)->start($otherAdmin))
        ->toThrow(AuthorizationException::class);

    expect(session()->has(ImpersonationService::SESSION_KEY))->toBeFalse();
});

it('rejects impersonation started by a non-superadmin', function () {
    $owner = User::factory()->owner()->create();
    $target = User::factory()->owner()->create();

    $this->actingAs($owner);

    expect(fn () => app(ImpersonationService::class)->start($target))
        ->toThrow(AuthorizationException::class);
});

it('stops impersonation, restores the admin, and audits the stop', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($this->admin);
    app(ImpersonationService::class)->start($owner);

    app(ImpersonationService::class)->stop();

    expect(Auth::id())->toBe($this->admin->getKey())
        ->and(session()->has(ImpersonationService::SESSION_KEY))->toBeFalse();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'impersonation.stopped',
        'subject_id' => (string) $owner->getKey(),
        'impersonator_id' => $this->admin->getKey(),
    ]);
});

it('starts impersonation from the users table and redirects to the target panel', function () {
    $owner = User::factory()->owner()->create();
    BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();

    $this->actingAs($this->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('impersonate')->table($owner))
        ->assertRedirect('/app');

    expect(Auth::id())->toBe($owner->getKey());
});

it('ends impersonation through the stop route and returns to the admin panel', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($this->admin);
    app(ImpersonationService::class)->start($owner);

    // Auth is now the impersonated owner; the banner posts to this route.
    $this->post('/impersonation/stop')->assertRedirect('/admin');

    expect(Auth::id())->toBe($this->admin->getKey())
        ->and(session()->has(ImpersonationService::SESSION_KEY))->toBeFalse();
});

it('renders the impersonation banner when the session marker is present', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner);
    session()->put(ImpersonationService::SESSION_KEY, $this->admin->getKey());

    $html = view('impersonation-banner')->render();

    expect($html)->toContain('Impersonating')
        ->toContain($owner->getFilamentName())
        ->toContain(route('impersonation.stop'));
});
