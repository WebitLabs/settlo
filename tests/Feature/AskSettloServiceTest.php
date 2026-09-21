<?php

use App\Enums\MaritalStatus;
use App\Models\BusinessEntity;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Ai\AiException;
use App\Services\Ai\AskSettloService;
use App\Services\Ai\GeminiChatResponder;
use Database\Seeders\CantonSeeder;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->seed(CantonSeeder::class);
    // No Gemini key in tests → the container resolves the fake responder.
    config(['services.gemini.key' => null]);
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

    TaxProfile::factory()->for($user)->create([
        'canton_id' => $entity->canton_id,
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

it('calls the Gemini generateContent endpoint with the api key header, system instruction and role-mapped history', function () {
    Http::preventStrayRequests();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Yes — register once you cross the threshold.']]],
            ]],
            'usageMetadata' => ['totalTokenCount' => 60],
        ]),
    ]);

    $responder = new GeminiChatResponder(
        app(HttpFactory::class),
        'gk-test-key',
        'gemini-2.0-flash',
        'https://generativelanguage.googleapis.com/v1beta',
    );

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
        ->and($reply->model)->toBe('gemini-2.0-flash');

    Http::assertSent(function (Request $request) {
        return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent'
            && $request->hasHeader('x-goog-api-key', 'gk-test-key')
            && ! str_contains($request->url(), 'gk-test-key')
            && str_starts_with($request['system_instruction']['parts'][0]['text'], 'You are Settlo AI.')
            && $request['generationConfig']['maxOutputTokens'] === 8192
            && $request['generationConfig']['thinkingConfig']['thinkingLevel'] === 'low'
            && count($request['contents']) === 3
            && $request['contents'][0] === ['role' => 'user', 'parts' => [['text' => 'Do I need VAT?']]]
            && $request['contents'][1] === ['role' => 'model', 'parts' => [['text' => 'It depends on your turnover.']]]
            && $request['contents'][2] === ['role' => 'user', 'parts' => [['text' => 'My turnover is CHF 120k.']]];
    });
});

it('throws an AiException carrying only the status when the provider errors', function () {
    Http::preventStrayRequests();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['error' => 'sensitive provider detail'], 500),
    ]);

    $responder = new GeminiChatResponder(
        app(HttpFactory::class),
        'gk-test-key',
        'gemini-2.0-flash',
        'https://generativelanguage.googleapis.com/v1beta',
    );

    try {
        $responder->respond([['role' => 'user', 'content' => 'Hi']], 'You are Settlo AI.');
        $this->fail('Expected an AiException.');
    } catch (AiException $exception) {
        expect($exception->getMessage())
            ->toContain('HTTP 500')
            ->not->toContain('sensitive provider detail')
            ->not->toContain('gk-test-key');
    }
});

it('denies another owner from viewing a conversation they do not own', function () {
    [$user, $entity] = makeOwnerWithBusiness();

    $conversation = app(AskSettloService::class)->startConversation($user, $entity);

    $intruder = User::factory()->owner()->create();

    expect($intruder->can('view', $conversation))->toBeFalse()
        ->and($user->can('view', $conversation))->toBeTrue();
});

function fakeGeminiResponder(): GeminiChatResponder
{
    return new GeminiChatResponder(
        app(HttpFactory::class),
        'gk-test-key',
        'gemini-2.0-flash',
        'https://generativelanguage.googleapis.com/v1beta',
    );
}

it('marks a reply that hit the output token limit as shortened and logs a warning', function () {
    Http::preventStrayRequests();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'You need to register once your turnover']]],
                'finishReason' => 'MAX_TOKENS',
            ]],
            'usageMetadata' => ['totalTokenCount' => 1100, 'thoughtsTokenCount' => 982, 'candidatesTokenCount' => 38],
        ]),
    ]);

    Log::shouldReceive('warning')
        ->once()
        ->with('Ask Settlo reply hit the output token limit.', ['thoughts' => 982, 'visible' => 38]);

    $reply = fakeGeminiResponder()->respond([['role' => 'user', 'content' => 'Do I need VAT?']], 'You are Settlo AI.');

    expect($reply->content)
        ->toStartWith('You need to register once your turnover')
        ->toEndWith("\n\n(This answer was shortened. Ask me to continue for the rest.)")
        ->not->toContain('_(');
});

it('tells Gemini to answer in plain text without markdown (L5)', function () {
    Http::preventStrayRequests();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'Hello']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['totalTokenCount' => 10],
        ]),
    ]);

    fakeGeminiResponder()->respond([['role' => 'user', 'content' => 'Hi']], 'You are Settlo AI.');

    Http::assertSent(function (Request $request): bool {
        $instruction = (string) data_get($request->data(), 'system_instruction.parts.0.text');

        return str_starts_with($instruction, 'You are Settlo AI.')
            && str_contains($instruction, 'Do not use markdown')
            && str_contains($instruction, 'start each line with "- "');
    });
});

it('throws an AiException when the provider blocks the answer', function () {
    Http::preventStrayRequests();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => []],
                'finishReason' => 'SAFETY',
            ]],
        ]),
    ]);

    fakeGeminiResponder()->respond([['role' => 'user', 'content' => 'Hi']], 'You are Settlo AI.');
})->throws(AiException::class, 'The assistant could not answer this question.');

it('omits the thinking config when no thinking level is configured', function () {
    config(['services.gemini.chat_thinking_level' => null]);

    Http::preventStrayRequests();
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Hello.']]],
                'finishReason' => 'STOP',
            ]],
        ]),
    ]);

    fakeGeminiResponder()->respond([['role' => 'user', 'content' => 'Hi']], 'You are Settlo AI.');

    Http::assertSent(fn (Request $request): bool => $request['generationConfig'] === ['maxOutputTokens' => 8192]);
});
