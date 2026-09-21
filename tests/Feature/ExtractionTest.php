<?php

use App\Providers\AppServiceProvider;
use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\FakeExtractor;
use App\Services\Extraction\GeminiExtractor;
use App\Services\Extraction\ReceiptExtractor;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

it('binds the fake extractor when no gemini key is configured', function () {
    config()->set('services.gemini.key', null);

    expect(app(ReceiptExtractor::class))->toBeInstanceOf(FakeExtractor::class);
});

it('binds the gemini extractor when a key is configured', function () {
    config()->set('services.gemini.key', 'test-key');

    expect(app(ReceiptExtractor::class))->toBeInstanceOf(GeminiExtractor::class);
});

it('the fake extractor returns a deterministic result', function () {
    $result = (new FakeExtractor)->extract('fake-image-bytes', 'image/png');

    expect($result->vendorName)->toBe('SBB CFF FFS')
        ->and($result->totalAmount)->toBe(87.50)
        ->and($result->vatRate)->toBe(8.1);
});

it('the fake extractor marks its output so it cannot be mistaken for a real scan', function () {
    $result = (new FakeExtractor)->extract('fake-image-bytes', 'image/png');

    expect($result->confidence)->toBe(0.0)
        ->and($result->meta['model'])->toBe('fake')
        ->and($result->description)->toContain('simulated extraction')
        // Persisted verbatim as the expense's ocr_raw_data.
        ->and($result->toArray()['model_used'])->toBe('fake')
        ->and($result->toArray()['simulated'])->toBeTrue();
});

it('the gemini extractor records the real model name and is never marked simulated', function () {
    Http::fake([
        '*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['confidence' => 0.5])]]]]],
        ], 200),
    ]);

    $extractor = new GeminiExtractor(app(HttpFactory::class), 'k', 'gemini-2.0-flash', 'https://example.test/v1beta');

    expect($extractor->extract('bytes', 'image/png')->toArray())
        ->toMatchArray(['model_used' => 'gemini-2.0-flash', 'simulated' => false]);
});

it('refuses the simulated extractor outside local and testing', function () {
    expect(fn () => AppServiceProvider::requireLocalAiFallback('production'))
        ->toThrow(RuntimeException::class, 'GEMINI_API_KEY is required');

    expect(AppServiceProvider::requireLocalAiFallback('local'))->toBeNull();
});

it('the gemini extractor parses a structured response', function () {
    Http::fake([
        '*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'text' => json_encode([
                        'vendor_name' => 'Digitec',
                        'document_date' => '2026-05-02',
                        'total_amount' => 1290.00,
                        'currency' => 'CHF',
                        'vat_amount' => 96.75,
                        'vat_rate' => 8.1,
                        'category_hint' => 'hardware',
                        'description' => 'Laptop',
                        'confidence' => 0.88,
                    ]),
                ]]],
            ]],
        ], 200),
    ]);

    $extractor = new GeminiExtractor(app(HttpFactory::class), 'k', 'gemini-2.0-flash', 'https://example.test/v1beta');

    $result = $extractor->extract('fake-image-bytes', 'image/png');

    expect($result->vendorName)->toBe('Digitec')
        ->and($result->totalAmount)->toBe(1290.00)
        ->and($result->vatRate)->toBe(8.1)
        ->and($result->confidence)->toBe(0.88);
});

it('the gemini extractor sends the file bytes base64-encoded in the request body', function () {
    Http::fake([
        '*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['confidence' => 0.5])]]]]],
        ], 200),
    ]);

    $extractor = new GeminiExtractor(app(HttpFactory::class), 'k', 'gemini-2.0-flash', 'https://example.test/v1beta');
    $extractor->extract('raw-receipt-bytes', 'image/png');

    Http::assertSent(function ($request) {
        return data_get($request->data(), 'contents.0.parts.1.inline_data.data') === base64_encode('raw-receipt-bytes')
            && data_get($request->data(), 'contents.0.parts.1.inline_data.mime_type') === 'image/png';
    });
});

it('the gemini extractor sends the api key as a header, never in the url', function () {
    Http::fake([
        '*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['confidence' => 0.5])]]]]],
        ], 200),
    ]);

    $extractor = new GeminiExtractor(app(HttpFactory::class), 'secret-key', 'gemini-2.0-flash', 'https://example.test/v1beta');
    $extractor->extract('fake-image-bytes', 'image/png');

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-goog-api-key', 'secret-key')
            && ! str_contains($request->url(), 'secret-key');
    });
});

it('the gemini extractor rejects unsupported mime types', function () {
    $extractor = new GeminiExtractor(app(HttpFactory::class), 'k', 'm', 'https://example.test');

    expect(fn () => $extractor->extract('fake-image-bytes', 'application/x-msdownload'))
        ->toThrow(ExtractionException::class);
});

it('the gemini extractor rejects empty file contents', function () {
    Http::fake();

    $extractor = new GeminiExtractor(app(HttpFactory::class), 'k', 'm', 'https://example.test');

    expect(fn () => $extractor->extract('', 'image/png'))
        ->toThrow(ExtractionException::class, 'Uploaded file is empty.');

    Http::assertNothingSent();
});

it('the gemini extractor rejects files above the 20MB inline limit', function () {
    Http::fake();

    $extractor = new GeminiExtractor(app(HttpFactory::class), 'k', 'm', 'https://example.test');

    expect(fn () => $extractor->extract(str_repeat('a', GeminiExtractor::MAX_BYTES + 1), 'image/png'))
        ->toThrow(ExtractionException::class, 'Uploaded file exceeds the maximum size for extraction.');

    Http::assertNothingSent();
});

it('the gemini extractor throws on a provider error status', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $extractor = new GeminiExtractor(app(HttpFactory::class), 'k', 'm', 'https://example.test');

    expect(fn () => $extractor->extract('fake-image-bytes', 'image/png'))
        ->toThrow(ExtractionException::class);
});

it('the gemini extraction timeouts fit a 60 second serverless budget', function () {
    // Asserted against the config file's own defaults: a developer .env must
    // not decide whether the shipped default fits the function budget.
    $keys = ['GEMINI_EXTRACT_TIMEOUT', 'GEMINI_EXTRACT_CONNECT_TIMEOUT', 'GEMINI_EXTRACT_ATTEMPTS'];
    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = $_SERVER[$key] ?? $_ENV[$key] ?? null;
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    try {
        $gemini = (require config_path('services.php'))['gemini'];
    } finally {
        foreach (array_filter($saved, fn ($value): bool => $value !== null) as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    expect($gemini['extract_timeout'])->toBe(15)
        ->and($gemini['extract_connect_timeout'])->toBe(5)
        ->and($gemini['extract_attempts'])->toBe(2)
        // Worst case, including the 0.5s pause between attempts.
        ->and($gemini['extract_attempts'] * ($gemini['extract_connect_timeout'] + $gemini['extract_timeout']) + 1)
        ->toBeLessThan(60);
});
