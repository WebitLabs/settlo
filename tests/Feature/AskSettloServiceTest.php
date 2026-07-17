<?php

use App\Enums\MaritalStatus;
use App\Enums\VatStatus;
use App\Models\BusinessEntity;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AnthropicResponder;
use App\Services\Ai\AskSettloService;
use Database\Seeders\CantonSeeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(CantonSeeder::class);
    // No Anthropic key in tests → the container resolves the fake responder.
    config(['settlo.anthropic.api_key' => null]);
});

function makeOwnerWithBusiness(): array
{
    $user = User::factory()->owner()->create([
        'first_name' => 'Anna',
        'last_name' => 'Müller',
    ]);

    $entity = BusinessEntity::factory()
        ->for($user, 'owner')
        ->forCanton('ZH')
        ->create(['name' => 'Anna Müller Consulting']);

    TaxProfile::factory()->for($entity)->create([
        'canton_id' => $entity->canton_id,
        'vat_status' => VatStatus::NotRegistered,
        'marital_status' => MaritalStatus::Single,
        'number_of_children' => 2,
        'pillar3a_amount' => 7056,
    ]);

    return [$user, $entity];
}

it('creates a conversation and persists both messages with context snapshot and an auto-title', function () {
    [$user, $entity] = makeOwnerWithBusiness();

    $service = app(AskSettloService::class);
    $conversation = $service->startConversation($user, $entity);

    expect($conversation->user_id)->toBe($user->getKey())
        ->and($conversation->business_entity_id)->toBe($entity->getKey())
        ->and($conversation->title)->toBeNull();

    $assistant = $service->sendMessage($conversation, 'Do I need to register for VAT?');

    expect($assistant->role)->toBe('assistant')
        ->and($assistant->content)->toContain("100'000")
        ->and((float) $assistant->confidence)->toBe(0.94)
        ->and($assistant->tokens_used)->toBe(128)
        ->and($assistant->context_snapshot['canton_code'])->toBe('ZH')
        ->and($assistant->context_snapshot['number_of_children'])->toBe(2)
        ->and((float) $assistant->context_snapshot['pillar3a_amount'])->toBe(7056.0);

    $conversation->refresh();

    expect($conversation->title)->toBe('Do I need to register for VAT?')
        ->and($conversation->messages()->count())->toBe(2)
        ->and($conversation->messages()->where('role', 'user')->value('content'))
        ->toBe('Do I need to register for VAT?');
});

it('does not overwrite an explicit conversation title', function () {
    [$user, $entity] = makeOwnerWithBusiness();

    $service = app(AskSettloService::class);
    $conversation = $service->startConversation($user, $entity, 'My VAT thread');

    $service->sendMessage($conversation, 'Anything I should know about VAT?');

    expect($conversation->refresh()->title)->toBe('My VAT thread');
});

it('calls the Anthropic messages endpoint with the api key, version, system prompt and full history', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-4-20250514',
            'content' => [['type' => 'text', 'text' => 'Yes — register once you cross the threshold.']],
            'usage' => ['input_tokens' => 40, 'output_tokens' => 20],
        ]),
    ]);

    $responder = new AnthropicResponder(app(HttpFactory::class), 'sk-test-key', 'claude-sonnet-4-20250514', 1000);

    $reply = $responder->respond(
        [
            ['role' => 'user', 'content' => 'Do I need VAT?'],
            ['role' => 'assistant', 'content' => 'It depends on your turnover.'],
            ['role' => 'user', 'content' => 'My turnover is CHF 120k.'],
        ],
        'You are Settlo AI.',
    );

    expect($reply->content)->toBe('Yes — register once you cross the threshold.')
        ->and($reply->tokensUsed)->toBe(60)
        ->and($reply->confidence)->toBe(0.90)
        ->and($reply->model)->toBe('claude-sonnet-4-20250514');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'sk-test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $request['system'] === 'You are Settlo AI.'
            && $request['model'] === 'claude-sonnet-4-20250514'
            && count($request['messages']) === 3
            && $request['messages'][0] === ['role' => 'user', 'content' => 'Do I need VAT?']
            && $request['messages'][2] === ['role' => 'user', 'content' => 'My turnover is CHF 120k.'];
    });
});

it('throws an AiException carrying only the status when the provider errors', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.anthropic.com/*' => Http::response(['error' => 'sensitive provider detail'], 500),
    ]);

    $responder = new AnthropicResponder(app(HttpFactory::class), 'sk-test-key', 'claude-sonnet-4-20250514', 1000);

    try {
        $responder->respond([['role' => 'user', 'content' => 'Hi']], 'You are Settlo AI.');
        $this->fail('Expected an AiException.');
    } catch (AiException $exception) {
        expect($exception->getMessage())
            ->toContain('HTTP 500')
            ->not->toContain('sensitive provider detail')
            ->not->toContain('sk-test-key');
    }
});

it('denies another owner from viewing a conversation they do not own', function () {
    [$user, $entity] = makeOwnerWithBusiness();

    $conversation = app(AskSettloService::class)->startConversation($user, $entity);

    $intruder = User::factory()->owner()->create();

    expect($intruder->can('view', $conversation))->toBeFalse()
        ->and($user->can('view', $conversation))->toBeTrue();
});
