<?php

use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\BusinessEntity;
use App\Models\User;
use Database\Seeders\CantonSeeder;
use Illuminate\Support\Facades\Broadcast;

/**
 * Authorize a user for a private channel through the callback registered in
 * routes/channels.php, with the channel parameter as the string it arrives as.
 */
function authorizeChannel(string $pattern, User $user, string $parameter): bool
{
    $callback = Broadcast::driver()->getChannels()->get($pattern);

    expect($callback)->toBeCallable();

    return (bool) $callback($user, $parameter);
}

/**
 * Assign an accountant of the firm to the business.
 */
function assignChannelAccountant(AccountingFirm $firm, BusinessEntity $entity, User $accountant, bool $revoked = false): void
{
    AccountantAssignment::create([
        'accounting_firm_id' => $firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => $accountant->getKey(),
        'assigned_at' => now()->subMonth(),
        'revoked_at' => $revoked ? now() : null,
    ]);
}

beforeEach(function () {
    $this->seed(CantonSeeder::class);
});

describe('user channel', function () {
    it('only lets users listen to their own channel', function () {
        $user = User::factory()->owner()->create();
        $other = User::factory()->owner()->create();

        expect(authorizeChannel('App.Models.User.{id}', $user, (string) $user->getKey()))->toBeTrue()
            ->and(authorizeChannel('App.Models.User.{id}', $user, (string) $other->getKey()))->toBeFalse();
    });
});

describe('business channel', function () {
    beforeEach(function () {
        $this->owner = User::factory()->owner()->create();
        $this->entity = BusinessEntity::factory()->for($this->owner, 'owner')->create();
        $this->firm = AccountingFirm::factory()->create();
    });

    it('lets the owner listen to their business', function () {
        expect(authorizeChannel('business.{businessEntityId}', $this->owner, (string) $this->entity->getKey()))->toBeTrue();
    });

    it('rejects another owner', function () {
        $stranger = User::factory()->owner()->create();

        expect(authorizeChannel('business.{businessEntityId}', $stranger, (string) $this->entity->getKey()))->toBeFalse();
    });

    it('lets an actively assigned accountant listen', function () {
        $accountant = User::factory()->accountant()->create();
        assignChannelAccountant($this->firm, $this->entity, $accountant);

        expect(authorizeChannel('business.{businessEntityId}', $accountant, (string) $this->entity->getKey()))->toBeTrue();
    });

    it('rejects an accountant whose assignment was revoked or who is not assigned', function () {
        $revoked = User::factory()->accountant()->create();
        assignChannelAccountant($this->firm, $this->entity, $revoked, revoked: true);
        $unassigned = User::factory()->accountant()->create();

        expect(authorizeChannel('business.{businessEntityId}', $revoked, (string) $this->entity->getKey()))->toBeFalse()
            ->and(authorizeChannel('business.{businessEntityId}', $unassigned, (string) $this->entity->getKey()))->toBeFalse();
    });

    it('rejects superadmins and unknown businesses', function () {
        $admin = User::factory()->superadmin()->create();

        expect(authorizeChannel('business.{businessEntityId}', $admin, (string) $this->entity->getKey()))->toBeFalse()
            ->and(authorizeChannel('business.{businessEntityId}', $this->owner, 'b6a1c1a4-0000-4000-8000-000000000000'))->toBeFalse();
    });
});
