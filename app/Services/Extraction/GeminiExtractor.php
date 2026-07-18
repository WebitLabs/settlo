<?php

namespace App\Services\Extraction;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Extracts receipt/invoice data using Google Gemini's multimodal API.
 *
 * Security: the API key is read from server config and sent as a request
 * header (never embedded in a logged URL). The request body carries the file
 * and is never logged. Uploads are MIME- and size-validated before dispatch.
 */
class GeminiExtractor implements ReceiptExtractor
{
    /** Upload types Gemini can read. Keep in sync with expense upload validation. */
    public const array SUPPORTED_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'application/pdf',
    ];

    /** Hard ceiling on inline file size (Gemini inline-data limit is 20 MB). */
    public const int MAX_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $endpoint,
    ) {}

    public function extract(string $absolutePath, string $mimeType): ExtractionResult
    {
        if (! in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            throw new ExtractionException("Unsupported file type: {$mimeType}.");
        }

        if (! is_readable($absolutePath)) {
            throw new ExtractionException('Uploaded file is not readable.');
        }

        $bytes = filesize($absolutePath);
        if ($bytes === false || $bytes === 0) {
            throw new ExtractionException('Uploaded file is empty.');
        }
        if ($bytes > self::MAX_BYTES) {
            throw new ExtractionException('Uploaded file exceeds the maximum size for extraction.');
        }

        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new ExtractionException('Failed to read the uploaded file.');
        }

        try {
            $response = $this->http
                ->baseUrl($this->endpoint)
                ->timeout(60)
                ->connectTimeout(10)
                ->retry(2, 1000, throw: false)
                ->withHeaders(['x-goog-api-key' => $this->apiKey])
                ->post("/models/{$this->model}:generateContent", $this->payload($contents, $mimeType));
        } catch (Throwable $e) {
            throw new ExtractionException('Extraction request failed.', previous: $e);
        }

        if ($response->failed()) {
            // Log status only — the request body holds the file, the header holds the key.
            Log::warning('Gemini extraction returned an error status.', ['status' => $response->status()]);

            throw new ExtractionException("Extraction provider error (HTTP {$response->status()}).");
        }

        return $this->parse($response->json());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $contents, string $mimeType): array
    {
        return [
            'contents' => [[
                'parts' => [
                    ['text' => $this->prompt()],
                    ['inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => base64_encode($contents),
                    ]],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0,
                'responseMimeType' => 'application/json',
                'responseSchema' => $this->schema(),
            ],
        ];
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
            You are a data-extraction engine for Swiss business expense receipts and invoices.
            Extract the fields defined by the response schema from the attached document.
            Rules:
            - Amounts are numbers only: no currency symbols, no thousands separators.
            - total_amount is the gross total actually paid, including VAT.
            - vat_amount is the VAT/MwSt/TVA amount; vat_rate is its percentage (e.g. 8.1, 2.6, 3.8).
            - currency is the ISO 4217 code on the document; use CHF if none is shown.
            - document_date is the invoice/receipt date in YYYY-MM-DD format.
            - category_hint is a short lowercase English label for the expense type
              (e.g. "meals", "travel", "software", "office_supplies", "telecom").
            - confidence is your overall confidence from 0 to 1 that the values are correct.
            - Use null for any field not present on the document.
            Return only the JSON defined by the schema.
            PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vendor_name' => ['type' => 'string', 'nullable' => true],
                'document_date' => ['type' => 'string', 'nullable' => true],
                'total_amount' => ['type' => 'number', 'nullable' => true],
                'currency' => ['type' => 'string', 'nullable' => true],
                'vat_amount' => ['type' => 'number', 'nullable' => true],
                'vat_rate' => ['type' => 'number', 'nullable' => true],
                'category_hint' => ['type' => 'string', 'nullable' => true],
                'description' => ['type' => 'string', 'nullable' => true],
                'confidence' => ['type' => 'number'],
            ],
            'required' => ['confidence'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function parse(?array $body): ExtractionResult
    {
        // Newer Gemini models may interleave non-text parts (e.g. thought
        // signatures) — join every text-bearing part instead of assuming
        // the first part carries the answer.
        $parts = data_get($body, 'candidates.0.content.parts', []);
        $text = collect(is_array($parts) ? $parts : [])
            ->pluck('text')
            ->filter(fn ($piece): bool => is_string($piece))
            ->implode('');

        if ($text === '') {
            throw new ExtractionException('Extraction provider returned no content.');
        }

        $data = json_decode($text, true);
        if (! is_array($data)) {
            throw new ExtractionException('Extraction provider returned malformed JSON.');
        }

        return new ExtractionResult(
            vendorName: $this->str($data['vendor_name'] ?? null),
            documentDate: $this->str($data['document_date'] ?? null),
            totalAmount: $this->num($data['total_amount'] ?? null),
            currency: $this->str($data['currency'] ?? null) ?? 'CHF',
            vatAmount: $this->num($data['vat_amount'] ?? null),
            vatRate: $this->num($data['vat_rate'] ?? null),
            categoryHint: $this->str($data['category_hint'] ?? null),
            description: $this->str($data['description'] ?? null),
            confidence: (float) ($data['confidence'] ?? 0),
            meta: ['model' => $this->model],
        );
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function num(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
