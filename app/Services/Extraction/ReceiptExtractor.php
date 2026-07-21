<?php

namespace App\Services\Extraction;

interface ReceiptExtractor
{
    /**
     * Extract structured financial data from a receipt/invoice file.
     *
     * @param  string  $contents  Raw file bytes read from the (local or cloud) receipts disk.
     * @param  string  $mimeType  The file's validated MIME type.
     *
     * @throws ExtractionException When the file is empty, unsupported, oversized, or the provider fails.
     */
    public function extract(string $contents, string $mimeType): ExtractionResult;
}
