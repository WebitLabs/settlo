<?php

use App\Enums\AiEscalationStatus;
use App\Enums\MaritalStatus;
use App\Enums\VatStatus;
use App\Filament\App\Pages\AskSettlo;
use App\Jobs\SimulateAccountantAnswer;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\Subscription;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Ai\AskSettloService;
use App\Services\Ai\EscalationService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    config(['services.gemini.key' => null]);
});

/**
 * @return array{0: User, 1: BusinessEntity}
 */
function askOwner(string $planCode = 'pro', int $quota = 1): array
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

function askAssistantMessage(User $user, BusinessEntity $entity, string $question = 'Do I need to register for VAT?'): AiMessage
{
    $service = app(AskSettloService::class);
    $conversation = $service->startConversation($user, $entity);
    $service->sendMessage($conversation, $question);

    return $conversation->messages()->where('role', 'assistant')->firstOrFail();
}

it('redirects the legacy chat URL into the panel page', function () {
    [$user, $entity] = askOwner();

    $this->actingAs($user)
        ->get(route('ask-settlo.index', $entity))
        ->assertRedirect(AskSettlo::getUrl(['tenant' => $entity], panel: 'app'));
});

it('renders the panel chat page for the owner', function () {
    [$user, $entity] = askOwner();

    $this->actingAs($user)
        ->get(AskSettlo::getUrl(['tenant' => $entity], panel: 'app'))
        ->assertOk()
        ->assertSee('ask-settlo-root');
});

it('serves the chat bootstrap payload with conversations and quota', function () {
    [$user, $entity] = askOwner();
    askAssistantMessage($user, $entity);

    $this->actingAs($user)
        ->getJson(route('ask-settlo.bootstrap', $entity))
        ->assertOk()
        ->assertJsonCount(1, 'conversations')
        ->assertJsonCount(8, 'suggestedQuestions')
        ->assertJsonPath('quota.total', 1)
        ->assertJsonPath('quota.canEscalate', true)
        ->assertJsonStructure(['businessEntityId', 'conversations', 'activeConversation', 'context', 'quota', 'accountant', 'suggestedQuestions']);
});

it('forbids the bootstrap payload for another owner\'s entity', function () {
    [, $entity] = askOwner();
    $intruder = User::factory()->owner()->create();
    Subscription::factory()->for($intruder)->onPlan('pro', 1)->active()->create();

    $this->actingAs($intruder)
        ->getJson(route('ask-settlo.bootstrap', $entity))
        ->assertForbidden();
});

it('forbids access to another owner\'s business entity', function () {
    [, $entity] = askOwner();
    $intruder = User::factory()->owner()->create();
    Subscription::factory()->for($intruder)->onPlan('pro', 1)->active()->create();

    $this->actingAs($intruder)
        ->get(route('ask-settlo.index', $entity))
        ->assertForbidden();
});

it('sends a message and returns the assistant reply as JSON', function () {
    [$user, $entity] = askOwner();
    $conversation = app(AskSettloService::class)->startConversation($user, $entity);

    $this->actingAs($user)
        ->postJson(route('ask-settlo.messages.store', [$entity, $conversation]), [
            'content' => 'How is AHV calculated?',
        ])
        ->assertOk()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('role', 'assistant')
            ->has('content')
            ->has('confidence')
            ->etc());

    expect($conversation->messages()->where('role', 'user')->count())->toBe(1)
        ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(1);
});

it('streams a reply as an event-stream and persists both messages', function () {
    [$user, $entity] = askOwner();
    $conversation = app(AskSettloService::class)->startConversation($user, $entity);

    $response = $this->actingAs($user)
        ->post(route('ask-settlo.stream', [$entity, $conversation]), [
            'content' => 'Can I deduct home office costs?',
        ]);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/event-stream');

    $body = $response->streamedContent();
    expect($body)->toContain('event: delta')
        ->and($body)->toContain('event: done');

    expect($conversation->messages()->where('role', 'user')->count())->toBe(1)
        ->and($conversation->messages()->where('role', 'assistant')->count())->toBe(1);
});

it('returns the upgrade message when escalation quota is exhausted', function () {
    [$user, $entity] = askOwner('pro', 0);
    $answer = askAssistantMessage($user, $entity);

    $this->actingAs($user)
        ->postJson(route('ask-settlo.escalate', [$entity, $answer]))
        ->assertStatus(429)
        ->assertJson([
            'message' => 'Monthly limit reached — upgrade to Confidence for 3 answers/month.',
        ]);
});

it('rate-limits Ask Settlo requests per authenticated user', function () {
    config(['settlo.ask_settlo_rate_limit' => 2]);

    [$user, $entity] = askOwner();

    $this->actingAs($user)->getJson(route('ask-settlo.bootstrap', $entity))->assertOk();
    $this->actingAs($user)->getJson(route('ask-settlo.bootstrap', $entity))->assertOk();

    $this->actingAs($user)
        ->getJson(route('ask-settlo.bootstrap', $entity))
        ->assertStatus(429);
});

it('rejects a concurrent duplicate escalation with 409 without burning a second credit', function () {
    Queue::fake([SimulateAccountantAnswer::class]);

    [$user, $entity] = askOwner('pro', 1);
    $answer = askAssistantMessage($user, $entity);

    $this->actingAs($user)
        ->postJson(route('ask-settlo.escalate', [$entity, $answer]))
        ->assertCreated();

    $this->actingAs($user)
        ->postJson(route('ask-settlo.escalate', [$entity, $answer]))
        ->assertStatus(409)
        ->assertJson(['message' => 'This answer has already been escalated.']);

    expect($user->subscription->refresh()->human_answers_used)->toBe(1);

    $this->assertDatabaseCount('ai_escalations', 1);
});

it('escalates an answer and then resolves it', function () {
    Queue::fake([SimulateAccountantAnswer::class]);

    [$user, $entity] = askOwner('pro', 1);
    $answer = askAssistantMessage($user, $entity);

    $escalationResponse = $this->actingAs($user)
        ->postJson(route('ask-settlo.escalate', [$entity, $answer]))
        ->assertCreated()
        ->assertJson(fn (AssertableJson $json) => $json
            ->where('escalation.status', AiEscalationStatus::Pending->value)
            ->where('quota.used', 1)
            ->etc());

    $escalationId = $escalationResponse->json('escalation.id');

    // The owner may only resolve once the accountant has answered.
    app(EscalationService::class)->applyAnswer(
        AiEscalation::findOrFail($escalationId),
        'Register once you approach CHF 100,000.',
    );

    $this->actingAs($user)
        ->postJson(route('ask-settlo.escalations.resolve', [$entity, $escalationId]))
        ->assertOk()
        ->assertJson(['status' => AiEscalationStatus::Closed->value]);

    $this->assertDatabaseHas('ai_escalations', [
        'id' => $escalationId,
        'status' => AiEscalationStatus::Closed->value,
    ]);
});

it('rejects resolving a still-pending escalation with 409 and leaves the status unchanged', function () {
    Queue::fake([SimulateAccountantAnswer::class]);

    [$user, $entity] = askOwner('pro', 1);
    $answer = askAssistantMessage($user, $entity);

    $escalationId = $this->actingAs($user)
        ->postJson(route('ask-settlo.escalate', [$entity, $answer]))
        ->assertCreated()
        ->json('escalation.id');

    $this->actingAs($user)
        ->postJson(route('ask-settlo.escalations.resolve', [$entity, $escalationId]))
        ->assertStatus(409)
        ->assertJson(['message' => 'This escalation has not been answered yet.']);

    $this->assertDatabaseHas('ai_escalations', [
        'id' => $escalationId,
        'status' => AiEscalationStatus::Pending->value,
    ]);
});
