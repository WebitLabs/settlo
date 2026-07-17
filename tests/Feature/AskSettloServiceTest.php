<?php

use App\Enums\MaritalStatus;
use App\Enums\VatStatus;
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
            && $request['system_instruction']['parts'][0]['text'] === 'You are Settlo AI.'
            && $request['generationConfig']['maxOutputTokens'] === 1024
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
