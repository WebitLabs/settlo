<?php

use App\Enums\AiEscalationStatus;
use App\Events\AiEscalationUpdated;
use App\Filament\Firm\Resources\Escalations\EscalationResource;
use App\Filament\Firm\Resources\Escalations\Pages\ListEscalations;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\KnowledgeBaseEntry;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

function firmMember(AccountingFirm $firm, User $accountant, bool $owner = true): void
{
    AccountingFirmMember::create([
        'accounting_firm_id' => $firm->getKey(),
        'user_id' => $accountant->getKey(),
        'is_owner' => $owner,
        'joined_at' => now(),
    ]);
}

function assign(AccountingFirm $firm, BusinessEntity $entity, User $accountant): AccountantAssignment
{
    return AccountantAssignment::create([
        'accounting_firm_id' => $firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => $accountant->getKey(),
        'assigned_at' => now()->subDay(),
        'revoked_at' => null,
    ]);
}

function makeEscalation(BusinessEntity $entity, User $owner): AiEscalation
{
    $conversation = AiConversation::create([
        'user_id' => $owner->getKey(),
        'business_entity_id' => $entity->getKey(),
        'title' => 'Do I need to register for VAT?',
    ]);

    $message = new AiMessage;
    $message->forceFill([
        'conversation_id' => $conversation->getKey(),
        'role' => 'assistant',
        'content' => 'Not yet — you are under the CHF 100k threshold.',
    ])->save();

    $escalation = new AiEscalation;
    $escalation->forceFill([
        'conversation_id' => $conversation->getKey(),
        'message_id' => $message->getKey(),
        'user_id' => $owner->getKey(),
        'accounting_firm_id' => null,
        'status' => AiEscalationStatus::Pending->value,
        'user_question' => 'Do I need to register for VAT?',
        'ai_answer' => 'Not yet — you are under the CHF 100k threshold.',
        'sla_deadline' => now()->addDay(),
    ])->save();

    return $escalation;
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->firm = AccountingFirm::factory()->create();
    $this->accountant = User::factory()->accountant()->create();
    firmMember($this->firm, $this->accountant);

    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
    assign($this->firm, $this->entity, $this->accountant);
});

function actAsFirm(): void
{
    test()->actingAs(test()->accountant);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant(test()->firm);
}

it('lists only escalations for entities actively assigned to the firm', function () {
    $mine = makeEscalation($this->entity, $this->owner);

    $otherFirm = AccountingFirm::factory()->create();
    $otherOwner = User::factory()->owner()->create();
    $otherEntity = BusinessEntity::factory()->forCanton('BE')->for($otherOwner, 'owner')->create();
    assign($otherFirm, $otherEntity, User::factory()->accountant()->create());
    $foreign = makeEscalation($otherEntity, $otherOwner);

    actAsFirm();

    Livewire::test(ListEscalations::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);

    expect(EscalationResource::canCreate())->toBeFalse();
});

it('lets an accountant claim a pending escalation', function () {
    $escalation = makeEscalation($this->entity, $this->owner);

    actAsFirm();

    Livewire::test(ListEscalations::class)
        ->callAction(TestAction::make('claim')->table($escalation));

    $escalation->refresh();

    expect($escalation->accountant_id)->toBe($this->accountant->getKey())
        ->and($escalation->accounting_firm_id)->toBe($this->firm->getKey())
        ->and($escalation->status)->toBe(AiEscalationStatus::InProgress);
});

it('answers a claimed escalation, notifies the owner, broadcasts, and can publish to the knowledge base', function () {
    $escalation = makeEscalation($this->entity, $this->owner);
    $escalation->forceFill([
        'accountant_id' => $this->accountant->getKey(),
        'accounting_firm_id' => $this->firm->getKey(),
        'status' => AiEscalationStatus::InProgress->value,
    ])->save();

    Event::fake([AiEscalationUpdated::class]);

    actAsFirm();

    Livewire::test(ListEscalations::class)
        ->callAction(TestAction::make('answer')->table($escalation), [
            'accountant_answer' => 'Correct — register once you approach CHF 100,000.',
            'accountant_notes' => 'Flag for Q3 review.',
            'add_to_knowledge_base' => true,
        ]);

    $escalation->refresh();

    expect($escalation->status)->toBe(AiEscalationStatus::Answered)
        ->and($escalation->accountant_answer)->toContain('CHF 100,000')
        ->and($escalation->accountant_notes)->toBe('Flag for Q3 review.')
        ->and($escalation->add_to_knowledge_base)->toBeTrue();

    expect(KnowledgeBaseEntry::where('escalation_id', $escalation->getKey())->exists())->toBeTrue();

    $this->assertDatabaseHas('notifications', [
        'notifiable_id' => $this->owner->getKey(),
        'notifiable_type' => User::class,
    ]);

    Event::assertDispatched(AiEscalationUpdated::class, fn (AiEscalationUpdated $event) => $event->status === 'answered');
});

it('does not overwrite the claimant when a second accountant races the claim', function () {
    $escalation = makeEscalation($this->entity, $this->owner);

    // A first accountant already claimed the row atomically.
    $firstAccountant = User::factory()->accountant()->create();
    firmMember($this->firm, $firstAccountant, owner: false);
    $claimed = AiEscalation::whereKey($escalation->getKey())
        ->where('status', AiEscalationStatus::Pending->value)
        ->whereNull('accountant_id')
        ->update([
            'accountant_id' => $firstAccountant->getKey(),
            'accounting_firm_id' => $this->firm->getKey(),
            'status' => AiEscalationStatus::InProgress->value,
        ]);

    expect($claimed)->toBe(1);

    // A second accountant racing the same escalation cannot claim it and the
    // original claimant is left intact — no silent overwrite.
    actAsFirm();

    Livewire::test(ListEscalations::class)
        ->assertActionHidden(TestAction::make('claim')->table($escalation));

    // The atomic guard itself refuses the second write.
    $secondClaim = AiEscalation::whereKey($escalation->getKey())
        ->where('status', AiEscalationStatus::Pending->value)
        ->whereNull('accountant_id')
        ->update([
            'accountant_id' => $this->accountant->getKey(),
            'accounting_firm_id' => $this->firm->getKey(),
            'status' => AiEscalationStatus::InProgress->value,
        ]);

    expect($secondClaim)->toBe(0);

    $escalation->refresh();

    expect($escalation->accountant_id)->toBe($firstAccountant->getKey())
        ->and($escalation->status)->toBe(AiEscalationStatus::InProgress);
});

it('hides the claim action once an escalation is claimed', function () {
    $escalation = makeEscalation($this->entity, $this->owner);
    $escalation->forceFill([
        'accountant_id' => $this->accountant->getKey(),
        'status' => AiEscalationStatus::InProgress->value,
    ])->save();

    actAsFirm();

    Livewire::test(ListEscalations::class)
        ->assertActionHidden(TestAction::make('claim')->table($escalation));
});

it('lets an accountant answer via a firm-scoped assignment with no accountant_id', function () {
    // The invitation-accept flow binds a business to a firm without naming an
    // individual accountant (accountant_id NULL). A member of that firm must
    // still be able to answer the escalation.
    $firm = AccountingFirm::factory()->create();
    $accountant = User::factory()->accountant()->create();
    firmMember($firm, $accountant);

    $owner = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();

    AccountantAssignment::create([
        'accounting_firm_id' => $firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => null,
        'assigned_at' => now()->subDay(),
        'revoked_at' => null,
    ]);

    $escalation = makeEscalation($entity, $owner);

    expect($accountant->can('answer', $escalation))->toBeTrue();
});

it('denies answering to an accountant whose firm has no assignment to the business', function () {
    $escalation = makeEscalation($this->entity, $this->owner);

    $otherFirm = AccountingFirm::factory()->create();
    $stranger = User::factory()->accountant()->create();
    firmMember($otherFirm, $stranger);

    expect($stranger->can('answer', $escalation))->toBeFalse();
});

it('denies answering once the firm-scoped assignment is revoked', function () {
    $firm = AccountingFirm::factory()->create();
    $accountant = User::factory()->accountant()->create();
    firmMember($firm, $accountant);

    $owner = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create();

    AccountantAssignment::create([
        'accounting_firm_id' => $firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => null,
        'assigned_at' => now()->subDays(2),
        'revoked_at' => now()->subDay(),
    ]);

    $escalation = makeEscalation($entity, $owner);

    expect($accountant->can('answer', $escalation))->toBeFalse();
});

it('does not surface escalations to a firm without an active assignment', function () {
    $escalation = makeEscalation($this->entity, $this->owner);

    $otherFirm = AccountingFirm::factory()->create();
    $intruder = User::factory()->accountant()->create();
    firmMember($otherFirm, $intruder);

    $this->actingAs($intruder);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant($otherFirm);

    Livewire::test(ListEscalations::class)
        ->assertOk()
        ->assertCanNotSeeTableRecords([$escalation]);
});
