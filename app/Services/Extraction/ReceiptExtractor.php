<?php

namespace App\Services\Extraction;

interface ReceiptExtractor
{
    /**
     * Extract structured financial data from a receipt/invoice file.
     *
     * @param  string  $absolutePath  Absolute path to the file on a private disk.
     * @param  string  $mimeType  The file's validated MIME type.
     *
     * @throws ExtractionException When the file is unreadable, unsupported, or the provider fails.
     */
    public function extract(string $absolutePath, string $mimeType): ExtractionResult;
}
