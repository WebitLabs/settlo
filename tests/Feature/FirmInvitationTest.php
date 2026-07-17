<?php

use App\Filament\Firm\Resources\Invitations\Pages\ListInvitations;
use App\Mail\FirmClientInvitationMail;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\BusinessEntity;
use App\Models\FirmClientInvitation;
use App\Models\User;
use App\Services\Firm\FirmInvitationService;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->firm = AccountingFirm::factory()->create(['name' => 'Müller Treuhand AG']);
    $this->accountant = User::factory()->accountant()->create();
    AccountingFirmMember::create([
        'accounting_firm_id' => $this->firm->getKey(),
        'user_id' => $this->accountant->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);
});

function actAsInvitingFirm(): void
{
    test()->actingAs(test()->accountant);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant(test()->firm);
}

it('creates an invitation storing only the token hash and emails the plain token URL', function () {
    Mail::fake();

    actAsInvitingFirm();

    Livewire::test(ListInvitations::class)
        ->callAction('invite', [
            'email' => 'anna@test.ch',
            'message' => 'Looking forward to working with you.',
        ]);

    $invitation = FirmClientInvitation::firstOrFail();

    expect($invitation->email)->toBe('anna@test.ch')
        ->and($invitation->token_hash)->not->toBeNull()
        ->and(strlen($invitation->token_hash))->toBe(64)
        ->and($invitation->accepted_at)->toBeNull();

    Mail::assertSent(FirmClientInvitationMail::class, function (FirmClientInvitationMail $mail) use ($invitation) {
        $plainTokenStored = FirmClientInvitation::where('token_hash', $mail->token)->exists();

        return $mail->hasTo('anna@test.ch')
            && ! $plainTokenStored
            && hash('sha256', $mail->token) === $invitation->token_hash;
    });
});

it('resend rotates the token hash and re-emails', function () {
    Mail::fake();

    $service = app(FirmInvitationService::class);
    $invitation = $service->invite($this->firm, 'anna@test.ch', $this->accountant);
    $firstHash = $invitation->token_hash;

    $service->resend($invitation->fresh());

    expect($invitation->fresh()->token_hash)->not->toBe($firstHash);

    Mail::assertSent(FirmClientInvitationMail::class, 2);
});

it('accepts an invitation: creates the assignment and marks it accepted', function () {
    Mail::fake();

    $owner = User::factory()->owner()->create(['email' => 'anna@test.ch']);
    $entity = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();

    $service = app(FirmInvitationService::class);
    $invitation = $service->invite($this->firm, 'anna@test.ch', $this->accountant);
    $token = Mail::sent(FirmClientInvitationMail::class)->first()->token;

    $this->actingAs($owner)
        ->get(route('firm-invitations.accept', ['token' => $token]))
        ->assertOk()
        ->assertSee($entity->name);

    $this->actingAs($owner)
        ->post(route('firm-invitations.store', ['token' => $token]), [
            'business_entity_id' => $entity->getKey(),
        ])
        ->assertRedirect('/app/'.$entity->getKey());

    expect($invitation->fresh()->accepted_at)->not->toBeNull()
        ->and($invitation->fresh()->accepted_by_id)->toBe($owner->getKey());

    $this->assertDatabaseHas('accountant_assignments', [
        'accounting_firm_id' => $this->firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'revoked_at' => null,
    ]);
});

it('forbids accepting when the signed-in email does not match the invitation', function () {
    Mail::fake();

    $service = app(FirmInvitationService::class);
    $service->invite($this->firm, 'anna@test.ch', $this->accountant);
    $token = Mail::sent(FirmClientInvitationMail::class)->first()->token;

    $intruder = User::factory()->owner()->create(['email' => 'someone-else@test.ch']);

    $this->actingAs($intruder)
        ->get(route('firm-invitations.accept', ['token' => $token]))
        ->assertForbidden();
});

it('rejects an expired invitation', function () {
    Mail::fake();

    $service = app(FirmInvitationService::class);
    $owner = User::factory()->owner()->create(['email' => 'anna@test.ch']);
    $invitation = $service->invite($this->firm, 'anna@test.ch', $this->accountant);
    $token = Mail::sent(FirmClientInvitationMail::class)->first()->token;

    $invitation->forceFill(['expires_at' => now()->subDay()])->save();

    $this->actingAs($owner)
        ->get(route('firm-invitations.accept', ['token' => $token]))
        ->assertNotFound();
});

it('scopes the invitation list to the current firm tenant', function () {
    $service = app(FirmInvitationService::class);
    Mail::fake();

    $mine = $service->invite($this->firm, 'anna@test.ch', $this->accountant);

    $otherFirm = AccountingFirm::factory()->create();
    $otherAccountant = User::factory()->accountant()->create();
    AccountingFirmMember::create([
        'accounting_firm_id' => $otherFirm->getKey(),
        'user_id' => $otherAccountant->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);
    $theirs = $service->invite($otherFirm, 'bob@test.ch', $otherAccountant);

    actAsInvitingFirm();

    Livewire::test(ListInvitations::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});
