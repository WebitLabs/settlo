<?php

use App\Enums\AiEscalationStatus;
use App\Filament\Firm\Pages\FirmSettings;
use App\Filament\Firm\Resources\Escalations\Pages\ListEscalations;
use App\Filament\Firm\Resources\Invitations\InvitationResource;
use App\Filament\Firm\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Firm\Resources\Members\Pages\ListMembers;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\AuditLog;
use App\Models\FirmClientInvitation;
use App\Models\User;
use App\Services\Firm\FirmInvitationService;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    Mail::fake();

    $this->firm = AccountingFirm::factory()->create(['name' => 'Müller Treuhand AG']);

    $this->firmOwner = User::factory()->accountant()->create();
    $this->firmStaff = User::factory()->accountant()->create();

    AccountingFirmMember::create([
        'accounting_firm_id' => $this->firm->getKey(),
        'user_id' => $this->firmOwner->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);
    AccountingFirmMember::create([
        'accounting_firm_id' => $this->firm->getKey(),
        'user_id' => $this->firmStaff->getKey(),
        'is_owner' => false,
        'joined_at' => now(),
    ]);
});

function actAsFirmUser(User $user): void
{
    test()->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant(test()->firm);
}

function pendingInvitation(string $email = 'client@test.ch'): FirmClientInvitation
{
    $invitation = new FirmClientInvitation;
    $invitation->forceFill([
        'accounting_firm_id' => test()->firm->getKey(),
        'invited_by_id' => test()->firmOwner->getKey(),
        'email' => $email,
        'token_hash' => hash('sha256', 'plain-'.$email),
        'expires_at' => now()->addDays(7),
    ])->save();

    return $invitation;
}

/*
|--------------------------------------------------------------------------
| Audit trail on firm-panel mutations
|--------------------------------------------------------------------------
*/

it('audits inviting, re-sending and revoking a client invitation', function () {
    actAsFirmUser($this->firmOwner);

    Livewire::test(ListInvitations::class)
        ->callAction('invite', ['email' => 'anna@test.ch', 'message' => null]);

    $invitation = FirmClientInvitation::where('email', 'anna@test.ch')->firstOrFail();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'firm.client_invited',
        'actor_id' => $this->firmOwner->getKey(),
        'subject_id' => $invitation->getKey(),
    ]);

    Livewire::test(ListInvitations::class)
        ->callAction(TestAction::make('resend')->table($invitation));

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'firm.client_invitation_resent',
        'actor_id' => $this->firmOwner->getKey(),
    ]);

    Livewire::test(ListInvitations::class)
        ->callAction(TestAction::make('revoke')->table($invitation->fresh()));

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'firm.client_invitation_revoked',
        'actor_id' => $this->firmOwner->getKey(),
    ]);

    expect(FirmClientInvitation::whereKey($invitation->getKey())->exists())->toBeFalse();
});

it('audits the client accepting an invitation', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');
    $owner->forceFill(['email' => 'client@test.ch'])->save();

    $invitation = pendingInvitation();

    $this->actingAs($owner)
        ->post(route('firm-invitations.store', 'plain-client@test.ch'), [
            'business_entity_id' => $entity->getKey(),
        ])->assertRedirect();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'firm.client_invitation_accepted',
        'actor_id' => $owner->getKey(),
        'subject_id' => $invitation->getKey(),
    ]);
});

it('audits adding, promoting and removing a team member', function () {
    $newcomer = User::factory()->accountant()->create(['email' => 'new@firm.ch']);

    actAsFirmUser($this->firmOwner);

    Livewire::test(ListMembers::class)
        ->callAction('addMember', ['email' => 'new@firm.ch']);

    $member = AccountingFirmMember::where('user_id', $newcomer->getKey())->firstOrFail();

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'firm.member_added',
        'actor_id' => $this->firmOwner->getKey(),
        'subject_id' => $member->getKey(),
    ]);

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('toggleOwner')->table($member));

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'firm.member_role_changed',
        'actor_id' => $this->firmOwner->getKey(),
    ]);

    Livewire::test(ListMembers::class)
        ->callAction(TestAction::make('remove')->table($member->fresh()));

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'firm.member_removed',
        'actor_id' => $this->firmOwner->getKey(),
    ]);
});

it('audits a firm settings change', function () {
    actAsFirmUser($this->firmOwner);

    Livewire::test(FirmSettings::class)
        ->fillForm(['name' => 'Müller & Partner AG'])
        ->call('save');

    expect($this->firm->fresh()->name)->toBe('Müller & Partner AG');

    $log = AuditLog::where('action', 'firm.settings_updated')->firstOrFail();

    expect($log->actor_id)->toBe($this->firmOwner->getKey())
        ->and($log->properties['changed'])->toContain('name');
});

it('audits an accountant answering an escalation', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');

    AccountantAssignment::create([
        'accounting_firm_id' => $this->firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => null,
        'assigned_at' => now()->subDay(),
    ]);

    $conversation = AiConversation::create([
        'user_id' => $owner->getKey(),
        'business_entity_id' => $entity->getKey(),
        'title' => 'VAT',
    ]);
    $message = new AiMessage;
    $message->forceFill([
        'conversation_id' => $conversation->getKey(),
        'role' => 'assistant',
        'content' => 'Not yet.',
    ])->save();

    $escalation = new AiEscalation;
    $escalation->forceFill([
        'conversation_id' => $conversation->getKey(),
        'message_id' => $message->getKey(),
        'user_id' => $owner->getKey(),
        'accounting_firm_id' => $this->firm->getKey(),
        'accountant_id' => $this->firmStaff->getKey(),
        'status' => AiEscalationStatus::InProgress->value,
        'user_question' => 'Do I need VAT?',
        'ai_answer' => 'Not yet.',
        'sla_deadline' => now()->addDay(),
    ])->save();

    actAsFirmUser($this->firmStaff);

    Livewire::test(ListEscalations::class)
        ->callAction(TestAction::make('answer')->table($escalation), [
            'accountant_answer' => 'Correct — register near CHF 100,000.',
            'accountant_notes' => null,
            'add_to_knowledge_base' => false,
        ]);

    $log = AuditLog::where('action', 'escalation.answered')->firstOrFail();

    expect($log->actor_id)->toBe($this->firmStaff->getKey())
        ->and($log->subject_id)->toBe($escalation->getKey())
        ->and($log->properties['accounting_firm_id'])->toBe($this->firm->getKey())
        ->and($log->properties['simulated'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Firm-owner gating on client invitations
|--------------------------------------------------------------------------
*/

it('hides the invite and revoke actions from a non-owner member', function () {
    $invitation = pendingInvitation();

    actAsFirmUser($this->firmStaff);

    Livewire::test(ListInvitations::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$invitation])
        ->assertActionHidden('invite')
        ->assertActionHidden(TestAction::make('revoke')->table($invitation))
        ->assertActionHidden(TestAction::make('resend')->table($invitation));
});

it('refuses a revoke or invite attempted by a non-owner member', function () {
    $invitation = pendingInvitation();

    actAsFirmUser($this->firmStaff);

    // The gate is the policy, not the hidden button: a crafted request is
    // refused even though the action never renders for this member.
    expect($this->firmStaff->can('delete', $invitation))->toBeFalse()
        ->and($this->firmStaff->can('update', $invitation))->toBeFalse()
        ->and($this->firmStaff->can('create', FirmClientInvitation::class))->toBeFalse()
        ->and(InvitationResource::currentUserIsFirmOwner())->toBeFalse()
        ->and(InvitationResource::canCreate())->toBeFalse();

    Livewire::test(ListInvitations::class)
        ->assertActionHidden(TestAction::make('revoke')->table($invitation));

    expect(FirmClientInvitation::whereKey($invitation->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Re-inviting a client the firm previously let go
|--------------------------------------------------------------------------
*/

it('reinstates a revoked assignment when a client is re-invited', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');
    $owner->forceFill(['email' => 'client@test.ch'])->save();

    $first = pendingInvitation();
    app(FirmInvitationService::class)->accept($first, $entity, $owner);

    $assignment = AccountantAssignment::where('business_entity_id', $entity->getKey())->firstOrFail();
    $assignment->forceFill(['revoked_at' => now()])->save();

    // (firm, business) is unique, so the second accept used to hit a duplicate
    // key instead of reinstating the existing row.
    $second = new FirmClientInvitation;
    $second->forceFill([
        'accounting_firm_id' => $this->firm->getKey(),
        'invited_by_id' => $this->firmOwner->getKey(),
        'email' => 'client@test.ch',
        'token_hash' => hash('sha256', 'second-token'),
        'expires_at' => now()->addDays(7),
    ])->save();

    $reinstated = app(FirmInvitationService::class)->accept($second, $entity, $owner);

    expect($reinstated->getKey())->toBe($assignment->getKey())
        ->and($reinstated->revoked_at)->toBeNull()
        ->and(AccountantAssignment::where('business_entity_id', $entity->getKey())->count())->toBe(1)
        ->and($second->fresh()->accepted_at)->not->toBeNull();
});

it('is idempotent when the same invitation is accepted twice', function () {
    [$owner, $entity] = workspaceOwner('pro', 'ZH');
    $owner->forceFill(['email' => 'client@test.ch'])->save();

    $invitation = pendingInvitation();

    $first = app(FirmInvitationService::class)->accept($invitation, $entity, $owner);
    $second = app(FirmInvitationService::class)->accept($invitation, $entity, $owner);

    expect($first->getKey())->toBe($second->getKey())
        ->and(AccountantAssignment::count())->toBe(1);
});
