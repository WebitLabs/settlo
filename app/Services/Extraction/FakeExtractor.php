<?php

namespace App\Services\Extraction;

/**
 * Deterministic extractor used in tests and in local development without a
 * Gemini key, so the whole upload pipeline is exercisable without an external
 * call. Outside local/testing the container refuses to bind it at all.
 *
 * Every field it returns is marked as fabricated — the model name is the
 * literal "fake" and the confidence is 0 — so a stand-in result can never be
 * read as a real scan in the UI, in ocr_raw_data or in an export.
 */
class FakeExtractor implements ReceiptExtractor
{
    /** Written to ExtractionResult::$meta['model'] and persisted as model_used. */
    public const string MODEL = 'fake';

    public function extract(string $contents, string $mimeType): ExtractionResult
    {
        return new ExtractionResult(
            vendorName: 'SBB CFF FFS',
            documentDate: '2026-03-14',
            totalAmount: 87.50,
            currency: 'CHF',
            vatAmount: 6.63,
            vatRate: 8.1,
            categoryHint: 'travel',
            description: 'Half-fare rail travel Zürich–Bern (simulated extraction, not read from the uploaded file)',
            confidence: 0.0,
            meta: ['driver' => self::MODEL, 'model' => self::MODEL, 'simulated' => true],
        );
    }
}
