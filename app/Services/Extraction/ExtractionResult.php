<?php

namespace App\Services\Extraction;

/**
 * Structured data extracted from an uploaded receipt or invoice image/PDF.
 * All monetary values are in the document's own currency. Every field is
 * nullable because extraction is best-effort — the user always reviews and
 * confirms before an expense is persisted as deductible.
 */
final readonly class ExtractionResult
{
    /**
     * @param  array<string, mixed>  $meta  Non-secret provider metadata retained for audit/debugging.
     */
    public function __construct(
        public ?string $vendorName = null,
        public ?string $documentDate = null,
        public ?float $totalAmount = null,
        public string $currency = 'CHF',
        public ?float $vatAmount = null,
        public ?float $vatRate = null,
        public ?string $categoryHint = null,
        public ?string $description = null,
        public float $confidence = 0.0,
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vendor_name' => $this->vendorName,
            'document_date' => $this->documentDate,
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
            'vat_amount' => $this->vatAmount,
            'vat_rate' => $this->vatRate,
            'category_hint' => $this->categoryHint,
            'description' => $this->description,
            'confidence' => $this->confidence,
        ];
    }
}
