<?php

use App\Enums\UserStatus;
use App\Filament\Admin\Resources\AuditLogs\AuditLogResource;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\BusinessEntity;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->admin = User::factory()->superadmin()->create();
});

function actAsSuperadmin(): void
{
    test()->actingAs(test()->admin);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

it('blocks a non-superadmin from the admin panel resources', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get('/admin/users')->assertForbidden();
    $this->actingAs($owner)->get('/admin/audit-logs')->assertForbidden();
});

it('lets a superadmin list users', function () {
    $owner = User::factory()->owner()->create();
    $accountant = User::factory()->accountant()->create();

    actAsSuperadmin();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$this->admin, $owner, $accountant]);
});

it('suspends a user and writes an audit row', function () {
    $owner = User::factory()->owner()->create();

    actAsSuperadmin();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('suspend')->table($owner));

    expect($owner->fresh()->status)->toBe(UserStatus::Suspended);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'user.suspended',
        'subject_type' => $owner->getMorphClass(),
        'subject_id' => (string) $owner->getKey(),
    ]);
});

it('reactivates a suspended user and writes an audit row', function () {
    $owner = User::factory()->owner()->suspended()->create();

    actAsSuperadmin();

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('reactivate')->table($owner));

    expect($owner->fresh()->status)->toBe(UserStatus::Active);

    $this->assertDatabaseHas('audit_logs', [
        'actor_id' => $this->admin->getKey(),
        'action' => 'user.reactivated',
        'subject_id' => (string) $owner->getKey(),
    ]);
});

it('hides the suspend action for the acting superadmin themselves', function () {
    actAsSuperadmin();

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('suspend')->table($this->admin));
});

it('hides the impersonate action for a superadmin target', function () {
    $otherAdmin = User::factory()->superadmin()->create();

    actAsSuperadmin();

    Livewire::test(ListUsers::class)
        ->assertActionHidden(TestAction::make('impersonate')->table($otherAdmin));
});

it('shows entity and firm counts on the users table', function () {
    $owner = User::factory()->owner()->create();
    BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();

    actAsSuperadmin();

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$owner]);
});

it('records the actor from auth and stores request metadata', function () {
    $owner = User::factory()->owner()->create();

    $this->actingAs($this->admin);
    $log = app(AuditLogger::class)->log('user.suspended', $owner, ['test' => true]);

    expect($log->actor_id)->toBe($this->admin->getKey())
        ->and($log->impersonator_id)->toBeNull()
        ->and($log->action)->toBe('user.suspended')
        ->and($log->properties)->toBe(['test' => true]);
});

it('exposes no create, edit, or delete surface on the audit log resource', function () {
    expect(AuditLogResource::canCreate())->toBeFalse()
        ->and(AuditLogResource::canEdit(new AuditLog))->toBeFalse()
        ->and(AuditLogResource::canDelete(new AuditLog))->toBeFalse()
        ->and(array_keys(AuditLogResource::getPages()))->toBe(['index', 'view']);
});

it('exposes no create surface on the user resource', function () {
    expect(UserResource::canCreate())->toBeFalse()
        ->and(array_keys(UserResource::getPages()))->toBe(['index', 'view']);
});
