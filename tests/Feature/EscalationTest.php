<?php

use App\Billing\QuotaExceededException;
use App\Enums\AiEscalationStatus;
use App\Enums\MaritalStatus;
use App\Enums\VatStatus;
use App\Events\AiEscalationUpdated;
use App\Jobs\SimulateAccountantAnswer;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\Subscription;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Ai\AskSettloService;
use App\Services\Ai\EscalationService;
use App\Services\Billing\SubscriptionService;
use Database\Seeders\CantonSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(CantonSeeder::class);
    $this->seed(PlanSeeder::class);
    config(['services.gemini.key' => null]);
});

/**
 * @return array{0: User, 1: BusinessEntity}
 */
function ownerWithPlan(string $planCode, int $quota): array
{
    $user = User::factory()->owner()->create();

    $entity = BusinessEntity::factory()
        ->for($user, 'owner')
        ->forCanton('ZH')
        ->create();

    TaxProfile::factory()->for($entity)->create([
        'canton_id' => $entity->canton_id,
        'vat_status' => VatStatus::NotRegistered,
        'marital_status' => MaritalStatus::Single,
        'number_of_children' => 0,
        'pillar3a_amount' => 7056,
    ]);

    Subscription::factory()->for($user)->onPlan($planCode, $quota)->active()->create([
        'human_answers_quota' => $quota,
        'human_answers_used' => 0,
    ]);

    return [$user->fresh(), $entity];
}

function assistantAnswer(User $user, BusinessEntity $entity, string $question = 'Do I need to register for VAT?'): AiMessage
{
    $service = app(AskSettloService::class);
    $conversation = $service->startConversation($user, $entity);
    $service->sendMessage($conversation, $question);

    return $conversation->messages()->where('role', 'assistant')->firstOrFail();
}

it('escalates a Pro answer: pending record, quota consumed, event + queued simulation', function () {
    [$user, $entity] = ownerWithPlan('pro', 1);
    $answer = assistantAnswer($user, $entity);

    Event::fake([AiEscalationUpdated::class]);
    Queue::fake([SimulateAccountantAnswer::class]);

    $escalation = app(EscalationService::class)->escalate($answer, $user);

    expect($escalation->status)->toBe(AiEscalationStatus::Pending)
        ->and($escalation->user_question)->toBe('Do I need to register for VAT?')
        ->and($escalation->ai_answer)->toBe($answer->content)
        ->and($escalation->sla_deadline)->not->toBeNull()
        ->and($user->subscription->refresh()->human_answers_used)->toBe(1);

    Event::assertDispatched(AiEscalationUpdated::class, function (AiEscalationUpdated $event) use ($entity, $escalation) {
        return $event->businessEntityId === $entity->getKey()
            && $event->escalationId === $escalation->getKey()
            && $event->status === 'pending'
            && $event->answeredAt === null;
    });

    Queue::assertPushed(SimulateAccountantAnswer::class, function (SimulateAccountantAnswer $job) use ($escalation) {
        return $job->escalationId === $escalation->getKey()
            && $job->queue === 'ai'
            && $job->delay !== null;
    });
});

it('blocks a second escalation once the Pro quota of 1 is spent', function () {
    [$user, $entity] = ownerWithPlan('pro', 1);
    $first = assistantAnswer($user, $entity, 'First question about VAT?');
    $second = assistantAnswer($user, $entity, 'Second question about AHV?');

    Event::fake([AiEscalationUpdated::class]);
    Queue::fake([SimulateAccountantAnswer::class]);

    $service = app(EscalationService::class);
    $service->escalate($first, $user);

    expect(fn () => $service->escalate($second, $user->fresh()))
        ->toThrow(QuotaExceededException::class);
});

it('rolls back the human-answer credit when a concurrent request wins the escalation race', function () {
    [$user, $entity] = ownerWithPlan('pro', 1);
    $answer = assistantAnswer($user, $entity);

    // Simulate a racing request that commits its own escalation for the same
    // answer after our exists() check but before our insert: consumeHumanAnswer
    // spends the credit, then a conflicting row lands. The unique message_id
    // insert must then fail and roll the whole transaction back — including the
    // credit spend — so no scarce credit is burned without an escalation.
    $subscriptions = Mockery::mock(SubscriptionService::class);
    $subscriptions->shouldReceive('consumeHumanAnswer')
        ->once()
        ->andReturnUsing(function (Subscription $subscription) use ($answer, $user): void {
            Subscription::whereKey($subscription->getKey())->increment('human_answers_used');

            AiEscalation::create([
                'conversation_id' => $answer->conversation_id,
                'message_id' => $answer->getKey(),
                'user_id' => $user->getKey(),
                'user_question' => 'concurrent',
                'ai_answer' => 'concurrent',
            ]);
        });

    $service = new EscalationService($subscriptions);

    expect(fn () => $service->escalate($answer, $user))
        ->toThrow(QueryException::class);

    expect($user->subscription->refresh()->human_answers_used)->toBe(0);
});

it('denies escalation to a Solo owner without accountant access', function () {
    [$user, $entity] = ownerWithPlan('solo', 0);
    $answer = assistantAnswer($user, $entity);

    expect(fn () => app(EscalationService::class)->escalate($answer, $user))
        ->toThrow(AuthorizationException::class);

    expect($user->subscription->refresh()->human_answers_used)->toBe(0);
});

it('runs the simulated answer job: answered status, timestamp, owner notification, broadcast', function () {
    [$user, $entity] = ownerWithPlan('pro', 1);
    $answer = assistantAnswer($user, $entity);

    Event::fake([AiEscalationUpdated::class]);
    Queue::fake([SimulateAccountantAnswer::class]);

    $escalation = app(EscalationService::class)->escalate($answer, $user);

    (new SimulateAccountantAnswer($escalation->getKey()))->handle(app(EscalationService::class));

    $escalation->refresh();

    expect($escalation->status)->toBe(AiEscalationStatus::Answered)
        ->and($escalation->answered_at)->not->toBeNull()
        ->and($escalation->accountant_answer)->toContain('Maria Schneider')
        ->and($escalation->accountant_answer)->toContain('CHF 100,000')
        ->and($escalation->sla_breached)->toBeFalse();

    $this->assertDatabaseHas('notifications', [
        'notifiable_id' => $user->getKey(),
        'notifiable_type' => User::class,
    ]);

    Event::assertDispatched(AiEscalationUpdated::class, function (AiEscalationUpdated $event) {
        return $event->status === 'answered' && $event->answeredAt !== null;
    });
});

it('marks an answered escalation resolved', function () {
    [$user, $entity] = ownerWithPlan('pro', 1);
    $answer = assistantAnswer($user, $entity);

    Event::fake([AiEscalationUpdated::class]);
    Queue::fake([SimulateAccountantAnswer::class]);

    $escalation = app(EscalationService::class)->escalate($answer, $user);
    app(EscalationService::class)->applyAnswer($escalation, 'Register once you approach CHF 100,000.');

    app(EscalationService::class)->markResolved($escalation->fresh(), $user);

    $escalation->refresh();

    expect($escalation->status)->toBe(AiEscalationStatus::Closed)
        ->and($escalation->resolved_at)->not->toBeNull();
});

it('refuses to resolve a still-pending escalation', function () {
    [$user, $entity] = ownerWithPlan('pro', 1);
    $answer = assistantAnswer($user, $entity);

    Event::fake([AiEscalationUpdated::class]);
    Queue::fake([SimulateAccountantAnswer::class]);

    $escalation = app(EscalationService::class)->escalate($answer, $user);

    expect(fn () => app(EscalationService::class)->markResolved($escalation, $user))
        ->toThrow(InvalidArgumentException::class);

    $escalation->refresh();

    expect($escalation->status)->toBe(AiEscalationStatus::Pending)
        ->and($escalation->resolved_at)->toBeNull();
});

it('enforces the escalation policy across tenants and roles', function () {
    [$user, $entity] = ownerWithPlan('pro', 1);
    $answer = assistantAnswer($user, $entity);

    Event::fake([AiEscalationUpdated::class]);
    Queue::fake([SimulateAccountantAnswer::class]);

    $escalation = app(EscalationService::class)->escalate($answer, $user);

    $intruder = User::factory()->owner()->create();

    $firm = AccountingFirm::factory()->create();
    $assignedAccountant = User::factory()->create(['role' => 'accountant']);
    AccountantAssignment::create([
        'accounting_firm_id' => $firm->getKey(),
        'business_entity_id' => $entity->getKey(),
        'accountant_id' => $assignedAccountant->getKey(),
        'assigned_at' => now(),
        'revoked_at' => null,
    ]);

    $strangerAccountant = User::factory()->create(['role' => 'accountant']);

    expect($user->can('view', $escalation))->toBeTrue()
        ->and($user->can('resolve', $escalation))->toBeTrue()
        ->and($intruder->can('view', $escalation))->toBeFalse()
        ->and($intruder->can('resolve', $escalation))->toBeFalse()
        ->and($assignedAccountant->can('answer', $escalation))->toBeTrue()
        ->and($assignedAccountant->can('view', $escalation))->toBeFalse()
        ->and($strangerAccountant->can('answer', $escalation))->toBeFalse();
});

it('restores human-answer availability after a quota reset', function () {
    [$user] = ownerWithPlan('pro', 1);
    $subscription = $user->subscription;
    $service = app(SubscriptionService::class);

    $service->consumeHumanAnswer($subscription);

    expect(fn () => $service->consumeHumanAnswer($subscription->refresh()))
        ->toThrow(QuotaExceededException::class);

    $service->resetQuota($subscription->refresh());

    $service->consumeHumanAnswer($subscription->refresh());

    expect($subscription->refresh()->human_answers_used)->toBe(1);
});
