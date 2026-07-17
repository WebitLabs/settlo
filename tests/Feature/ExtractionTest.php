<?php

use App\Services\Extraction\ExtractionException;
use App\Services\Extraction\FakeExtractor;
use App\Services\Extraction\GeminiExtractor;
use App\Services\Extraction\ReceiptExtractor;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

function tempReceipt(string $bytes = 'fake-image-bytes'): string
{
    $path = tempnam(sys_get_temp_dir(), 'receipt');
    file_put_contents($path, $bytes);

    return $path;
}

it('binds the fake extractor when no gemini key is configured', function () {
    config()->set('services.gemini.key', null);

    expect(app(ReceiptExtractor::class))->toBeInstanceOf(FakeExtractor::class);
});

it('binds the gemini extractor when a key is configured', function () {
    config()->set('services.gemini.key', 'test-key');

    expect(app(ReceiptExtractor::class))->toBeInstanceOf(GeminiExtractor::class);
});

it('the fake extractor returns a deterministic result', function () {
    $result = (new FakeExtractor)->extract('/dev/null', 'image/png');

    expect($result->vendorName)->toBe('SBB CFF FFS')
        ->and($result->totalAmount)->toBe(87.50)
        ->and($result->vatRate)->toBe(8.1)
        ->and($result->confidence)->toBeGreaterThan(0.9);
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
    $path = tempReceipt();

    $result = $extractor->extract($path, 'image/png');
    unlink($path);

    expect($result->vendorName)->toBe('Digitec')
        ->and($result->totalAmount)->toBe(1290.00)
        ->and($result->vatRate)->toBe(8.1)
        ->and($result->confidence)->toBe(0.88);
});

it('the gemini extractor sends the api key as a header, never in the url', function () {
    Http::fake([
        '*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['confidence' => 0.5])]]]]],
        ], 200),
    ]);

    $extractor = new GeminiExtractor(app(HttpFactory::class), 'secret-key', 'gemini-2.0-flash', 'https://example.test/v1beta');
    $path = tempReceipt();
    $extractor->extract($path, 'image/png');
    unlink($path);

    Http::assertSent(function ($request) {
        return $request->hasHeader('x-goog-api-key', 'secret-key')
            && ! str_contains($request->url(), 'secret-key');
    });
});

it('the gemini extractor rejects unsupported mime types', function () {
    $extractor = new GeminiExtractor(app(HttpFactory::class), 'k', 'm', 'https://example.test');

    expect(fn () => $extractor->extract('/dev/null', 'application/x-msdownload'))
        ->toThrow(ExtractionException::class);
});

it('the gemini extractor throws on a provider error status', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    $extractor = new GeminiExtractor(app(HttpFactory::class), 'k', 'm', 'https://example.test');
    $path = tempReceipt();

    $call = fn () => $extractor->extract($path, 'image/png');

    expect($call)->toThrow(ExtractionException::class);
    unlink($path);
});
