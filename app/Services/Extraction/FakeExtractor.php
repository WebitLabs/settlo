<?php

namespace App\Services\Extraction;

/**
 * Deterministic extractor used in tests and whenever no Gemini key is
 * configured, so the whole upload pipeline is exercisable without an
 * external call.
 */
class FakeExtractor implements ReceiptExtractor
{
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
            description: 'Half-fare rail travel Zürich–Bern',
            confidence: 0.94,
            meta: ['driver' => 'fake'],
        );
    }
}
