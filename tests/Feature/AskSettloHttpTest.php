<?php

use App\Enums\AiEscalationStatus;
use App\Enums\MaritalStatus;
use App\Enums\VatStatus;
use App\Jobs\SimulateAccountantAnswer;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\Subscription;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Ai\AskSettloService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    config(['settlo.anthropic.api_key' => null]);
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

it('renders the chat page for the owner with conversations and quota props', function () {
    [$user, $entity] = askOwner();
    askAssistantMessage($user, $entity);

    $this->actingAs($user)
        ->get(route('ask-settlo.index', $entity))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('AskSettlo/Index')
            ->has('conversations', 1)
            ->has('quota', fn (Assert $quota) => $quota
                ->where('total', 1)
                ->where('canEscalate', true)
                ->etc())
            ->has('suggestedQuestions', 8)
            ->has('context')
            ->has('accountant'));
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

    $this->actingAs($user)
        ->postJson(route('ask-settlo.escalations.resolve', [$entity, $escalationId]))
        ->assertOk()
        ->assertJson(['status' => AiEscalationStatus::Closed->value]);

    $this->assertDatabaseHas('ai_escalations', [
        'id' => $escalationId,
        'status' => AiEscalationStatus::Closed->value,
    ]);
});
