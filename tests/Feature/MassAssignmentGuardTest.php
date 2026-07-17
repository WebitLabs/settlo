<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\User;

/**
 * Threat model: owner-self-escalates-to-superadmin (CRITICAL) and
 * cross-tenant-injection over-posting (HIGH). Security-critical columns must
 * never be mass assignable.
 */
it('does not allow privilege escalation through mass assignment', function () {
    $user = User::factory()->owner()->create();

    $user->fill([
        'first_name' => 'Mallory',
        'role' => 'superadmin',
        'status' => 'active',
        'email_verified_at' => now(),
    ]);
    $user->save();

    expect($user->fresh()->role)->toBe(UserRole::Owner);
});

it('keeps role and status out of the fillable set', function () {
    $fillable = (new User)->getFillable();

    expect($fillable)->not->toContain('role')
        ->and($fillable)->not->toContain('status')
        ->and($fillable)->not->toContain('email_verified_at');
});

it('does not allow tenant hopping through owner_id or business_entity_id over-posting', function () {
    expect((new BusinessEntity)->getFillable())->not->toContain('owner_id')
        ->and((new Invoice)->getFillable())->not->toContain('business_entity_id')
        ->and((new Invoice)->getFillable())->not->toContain('status')
        ->and((new Invoice)->getFillable())->not->toContain('total');
});

it('defaults a new user to the least-privileged role and unverified status', function () {
    $user = new User;

    expect($user->role)->toBe(UserRole::Owner)
        ->and($user->status)->toBe(UserStatus::PendingVerification);
});
