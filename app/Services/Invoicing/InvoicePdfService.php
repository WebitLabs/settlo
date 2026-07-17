<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;
use Illuminate\Support\Facades\App;
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
    public function __construct(
        private readonly QrBillService $qrBill,
        private readonly InvoiceService $invoices,
    ) {}

    /**
     * Static labels are localised to the invoice's own language (falling back to
     * English). The locale is switched only around view rendering — which the
     * dompdf loadView call performs synchronously — and always restored, so the
     * translation never leaks into the surrounding request.
     */
    public function render(Invoice $invoice): DomPDF
    {
        $invoice->loadMissing(['businessEntity', 'client', 'lineItems']);

        $locale = $invoice->language ?: 'en';
        $previousLocale = App::getLocale();
        App::setLocale($locale);

        try {
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
                    'vatBreakdown' => $this->invoices->vatBreakdown($invoice),
                    'paymentPart' => $this->qrBill->paymentPartHtml($invoice, $locale),
                ])
                ->setPaper('a4');
        } finally {
            App::setLocale($previousLocale);
        }
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
