<?php

use App\Enums\AiEscalationStatus;
use App\Filament\Firm\Resources\Escalations\Pages\ViewEscalation;
use App\Jobs\SimulateAccountantAnswer;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Ai\EscalationService;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    [$this->owner, $this->entity] = workspaceOwner('pro', 'ZH');

    $this->firm = AccountingFirm::factory()->create();
    $this->accountant = User::factory()->accountant()->create();
    AccountingFirmMember::create([
        'accounting_firm_id' => $this->firm->getKey(),
        'user_id' => $this->accountant->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);
});

/**
 * Give the firm an active assignment over the owner's business.
 */
function assignFirm(): AccountantAssignment
{
    return AccountantAssignment::create([
        'accounting_firm_id' => test()->firm->getKey(),
        'business_entity_id' => test()->entity->getKey(),
        'accountant_id' => null,
        'assigned_at' => now()->subDay(),
        'revoked_at' => null,
    ]);
}

/**
 * An escalated assistant answer raised through the real service.
 */
function raiseEscalation(): AiEscalation
{
    $conversation = AiConversation::create([
        'user_id' => test()->owner->getKey(),
        'business_entity_id' => test()->entity->getKey(),
        'title' => 'Do I need to register for VAT?',
    ]);

    AiMessage::create([
        'conversation_id' => $conversation->getKey(),
        'role' => 'user',
        'content' => 'Do I need to register for VAT?',
    ]);

    $answer = new AiMessage;
    $answer->forceFill([
        'conversation_id' => $conversation->getKey(),
        'role' => 'assistant',
        'content' => 'Not yet — you are under the CHF 100k threshold.',
    ])->save();

    return app(EscalationService::class)->escalate($answer, test()->owner);
}

function actAsFirmPanel(User $accountant, AccountingFirm $firm): void
{
    test()->actingAs($accountant);
    Filament::setCurrentPanel(Filament::getPanel('firm'));
    Filament::setTenant($firm);
}

/*
|--------------------------------------------------------------------------
| H2 — the accountant must be able to read what they are asked to verify
|--------------------------------------------------------------------------
*/

it('lets an assigned firm accountant open the escalation detail page', function () {
    Queue::fake([SimulateAccountantAnswer::class]);
    assignFirm();

    $escalation = raiseEscalation();

    actAsFirmPanel($this->accountant, $this->firm);

    expect($this->accountant->can('view', $escalation))->toBeTrue();

    Livewire::test(ViewEscalation::class, ['record' => $escalation->getKey()])
        ->assertOk()
        ->assertSee('Not yet');
});

it('denies the escalation detail page to an accountant from another firm', function () {
    Queue::fake([SimulateAccountantAnswer::class]);
    assignFirm();

    $escalation = raiseEscalation();

    $otherFirm = AccountingFirm::factory()->create();
    $stranger = User::factory()->accountant()->create();
    AccountingFirmMember::create([
        'accounting_firm_id' => $otherFirm->getKey(),
        'user_id' => $stranger->getKey(),
        'is_owner' => true,
        'joined_at' => now(),
    ]);

    expect($stranger->can('view', $escalation))->toBeFalse()
        ->and($stranger->can('answer', $escalation))->toBeFalse();

    actAsFirmPanel($stranger, $otherFirm);

    expect(fn () => Livewire::test(ViewEscalation::class, ['record' => $escalation->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('denies the escalation detail page once the assignment is revoked', function () {
    Queue::fake([SimulateAccountantAnswer::class]);
    $assignment = assignFirm();

    $escalation = raiseEscalation();

    expect($this->accountant->can('view', $escalation))->toBeTrue();

    $assignment->forceFill(['revoked_at' => now()])->save();

    expect($this->accountant->fresh()->can('view', $escalation))->toBeFalse();

    actAsFirmPanel($this->accountant, $this->firm);

    expect(fn () => Livewire::test(ViewEscalation::class, ['record' => $escalation->getKey()]))
        ->toThrow(ModelNotFoundException::class);
});

it('still denies the detail page to an unrelated owner', function () {
    Queue::fake([SimulateAccountantAnswer::class]);

    $escalation = raiseEscalation();
    $intruder = User::factory()->owner()->create();

    expect($intruder->can('view', $escalation))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| H3 — the canned answer only stands in when no firm is assigned
|--------------------------------------------------------------------------
*/

it('queues the simulated answer when no firm is assigned to the business', function () {
    Queue::fake([SimulateAccountantAnswer::class]);

    $escalation = raiseEscalation();

    expect($escalation->accounting_firm_id)->toBeNull();

    Queue::assertPushed(SimulateAccountantAnswer::class);
});

it('never queues the simulated answer when a firm is assigned', function () {
    Queue::fake([SimulateAccountantAnswer::class]);
    assignFirm();

    $escalation = raiseEscalation();

    expect($escalation->accounting_firm_id)->toBe($this->firm->getKey())
        ->and($escalation->status)->toBe(AiEscalationStatus::Pending);

    Queue::assertNothingPushed();
});

it('leaves an escalation pending for the firm when the simulated job runs anyway', function () {
    Queue::fake([SimulateAccountantAnswer::class]);
    assignFirm();

    $escalation = raiseEscalation();

    // Defence in depth: even a manually dispatched (or already queued) job must
    // not answer on a firm's behalf.
    (new SimulateAccountantAnswer($escalation->getKey()))->handle(app(EscalationService::class));

    expect($escalation->fresh()->status)->toBe(AiEscalationStatus::Pending)
        ->and($escalation->fresh()->accountant_answer)->toBeNull();
});

it('answers an unassigned escalation when the simulated job runs', function () {
    Queue::fake([SimulateAccountantAnswer::class]);

    $escalation = raiseEscalation();

    (new SimulateAccountantAnswer($escalation->getKey()))->handle(app(EscalationService::class));

    expect($escalation->fresh()->status)->toBe(AiEscalationStatus::Answered)
        ->and($escalation->fresh()->accountant_answer)->toContain('Maria Schneider');
});

it('honours the config switch that turns the stand-in off entirely', function () {
    config(['settlo.escalation.simulate_answer' => false]);

    Queue::fake([SimulateAccountantAnswer::class]);

    $escalation = raiseEscalation();

    Queue::assertNothingPushed();

    (new SimulateAccountantAnswer($escalation->getKey()))->handle(app(EscalationService::class));

    expect($escalation->fresh()->status)->toBe(AiEscalationStatus::Pending);
});

it('never burns a second human-answer credit for the simulated reply', function () {
    Queue::fake([SimulateAccountantAnswer::class]);

    raiseEscalation();

    $used = $this->entity->subscription->refresh()->human_answers_used;

    expect($used)->toBe(1);
});
