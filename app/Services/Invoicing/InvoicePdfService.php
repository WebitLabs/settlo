<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Renders an invoice (with an embedded Swiss QR payment part) to PDF.
 *
 * dompdf is hardened: remote resources are disabled (no SSRF via a remote
 * <img>/@import) and inline PHP is disabled. All user-supplied invoice content
 * is escaped in the Blade view, so the document cannot be used for injection.
 */
class InvoicePdfService
{
    public function __construct(private readonly QrBillService $qrBill) {}

    public function render(Invoice $invoice): DomPDF
    {
        $invoice->loadMissing(['businessEntity', 'client', 'lineItems']);

        return Pdf::setOptions([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isHtml5ParserEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
        ])
            ->loadView('invoices.pdf', [
                'invoice' => $invoice,
                'entity' => $invoice->businessEntity,
                'client' => $invoice->client,
                'paymentPart' => $this->qrBill->paymentPartHtml($invoice, $invoice->language ?: 'en'),
            ])
            ->setPaper('a4');
    }

    public function filename(Invoice $invoice): string
    {
        return str_replace(['/', '\\', ' '], '-', (string) $invoice->invoice_number).'.pdf';
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        $pdf = $this->render($invoice);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->filename($invoice),
            ['Content-Type' => 'application/pdf'],
        );
    }
}
