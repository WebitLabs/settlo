<?php

use App\Filament\Firm\Resources\Members\Pages\ListMembers;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

function member(AccountingFirm $firm, User $user, bool $owner): AccountingFirmMember
{
    return AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $user->getKey(),
        'is_owner' => $owner,
        'joined_at' => now(),
    ]);
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->firm = AccountingFirm::factory()->create();
    $this->ownerAccountant = User::factory()->accountant()->create();
    member($this->firm, $this->ownerAccountant, owner: true);
});

function actAsFirmOwner(): void
{
    test()->actingAs(test()->ownerAccountant);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant(test()->firm);
}

it('lets a firm owner add an existing accountant to the team', function () {
    $newcomer = User::factory()->accountant()->create(['email' => 'colleague@firm.ch']);

    actAsFirmOwner();

    Livewire::test(ListMembers::class)
        ->callAction('addMember', ['email' => 'colleague@firm.ch']);

    $this->assertDatabaseHas('accounting_firm_members', [
        'accounting_firm_id' => $this->firm->getKey(),
        'user_id' => $newcomer->getKey(),
        'is_owner' => false,
    ]);
});

it('refuses to add a non-accountant user', function () {
    User::factory()->owner()->create(['email' => 'not-an-accountant@test.ch']);

    actAsFirmOwner();

    Livewire::test(ListMembers::class)
        ->callAction('addMember', ['email' => 'not-an-accountant@test.ch']);

    $this->assertDatabaseMissing('accounting_firm_members', [
        'accounting_firm_id' => $this->firm->getKey(),
        'user_id' => User::where('email', 'not-an-accountant@test.ch')->value('id'),
    ]);
});

it('hides management actions from a non-owner member', function () {
    $plainMember = User::factory()->accountant()->create();
    $plainRecord = member($this->firm, $plainMember, owner: false);

    $this->actingAs($plainMember);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant($this->firm);

    Livewire::test(ListMembers::class)
        ->assertActionHidden('addMember')
        ->assertActionHidden(TestAction::make('toggleOwner')->table($plainRecord));
});

it('prevents removing the last owner', function () {
    $ownerRecord = AccountingFirmMember::where('accounting_firm_id', $this->firm->getKey())
        ->where('user_id', $this->ownerAccountant->getKey())
        ->firstOrFail();

    $colleague = User::factory()->accountant()->create();
    member($this->firm, $colleague, owner: false);

    actAsFirmOwner();

    // The owner cannot remove themselves at all (self-removal is hidden), and
    // demotion of the only owner is blocked.
    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('toggleOwner')->table($ownerRecord));

    expect($ownerRecord->fresh()->is_owner)->toBeTrue();
});

it('removes a non-owner member', function () {
    $colleague = User::factory()->accountant()->create();
    $record = member($this->firm, $colleague, owner: false);

    actAsFirmOwner();

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('remove')->table($record));

    $this->assertDatabaseMissing('accounting_firm_members', [
        'id' => $record->getKey(),
    ]);
});

it('scopes the team list to the current firm tenant', function () {
    $otherFirm = AccountingFirm::factory()->create();
    $otherMemberUser = User::factory()->accountant()->create();
    $otherRecord = member($otherFirm, $otherMemberUser, owner: true);

    $ownRecord = AccountingFirmMember::where('accounting_firm_id', $this->firm->getKey())->firstOrFail();

    actAsFirmOwner();

    Livewire::test(ListMembers::class)
        ->assertCanSeeTableRecords([$ownRecord])
        ->assertCanNotSeeTableRecords([$otherRecord]);
});
