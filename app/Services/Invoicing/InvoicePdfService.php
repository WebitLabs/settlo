<?php

namespace App\Services\Invoicing;

use App\Enums\InvoiceStatus;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Support\ActionThrottle;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPDF;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Renders an invoice (with an embedded Swiss QR payment part) to PDF.
 *
 * An issued invoice always renders its frozen creditor snapshot, so the
 * document stays exactly what the client received; only a draft preview falls
 * back to the live business entity.
 *
 * dompdf is hardened: remote resources are disabled (no SSRF via a remote
 * <img>/@import) and inline PHP is disabled. The logo is therefore inlined as
 * a data URI read from our own storage disk, never fetched over the network.
 * All user-supplied invoice content is escaped in the Blade view, so the
 * document cannot be used for injection.
 */
class InvoicePdfService
{
    /** Largest logo inlined into the PDF (the upload field allows 4 MB). */
    private const int MAX_LOGO_BYTES = 4 * 1024 * 1024;

    /** PDF renders allowed per actor per minute (see download()). */
    private const int MAX_RENDERS_PER_MINUTE = 60;

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

        $creditor = $this->creditorFor($invoice);
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
                    'creditor' => $creditor,
                    'isDraft' => $invoice->status === InvoiceStatus::Draft,
                    'logo' => $this->logoDataUri($invoice->businessEntity),
                    'vatRegistered' => $creditor->vatRegistered,
                    'client' => $invoice->client,
                    'vatBreakdown' => $this->invoices->vatBreakdown($invoice),
                    'paymentPart' => $this->qrBill->paymentPartHtml($invoice, $locale, $creditor),
                ])
                ->setPaper('a4');
        } finally {
            App::setLocale($previousLocale);
        }
    }

    public function filename(Invoice $invoice): string
    {
        $name = str_replace(['/', '\\', ' '], '-', (string) $invoice->invoice_number);

        return $invoice->status === InvoiceStatus::Draft ? "{$name}-draft.pdf" : "{$name}.pdf";
    }

    /**
     * Rendering is CPU-bound, so it is throttled per actor: the action is
     * triggered from a panel button with no HTTP route of its own, where route
     * middleware cannot reach.
     */
    public function download(Invoice $invoice): StreamedResponse
    {
        ActionThrottle::hit(
            'invoice-pdf',
            (string) (Auth::id() ?? request()->ip() ?? 'cli'),
            self::MAX_RENDERS_PER_MINUTE,
        );

        $pdf = $this->render($invoice);

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $this->filename($invoice),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * The creditor the document is rendered with. A draft has no snapshot yet,
     * so it previews with the live business and a provisional QR reference —
     * the reference that will actually be minted when it is sent.
     */
    private function creditorFor(Invoice $invoice): InvoiceCreditor
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return InvoiceCreditor::for($invoice);
        }

        $entity = $invoice->businessEntity;
        $iban = $entity instanceof BusinessEntity ? InvoiceCreditor::ibanFor($entity) : null;

        $reference = filled($iban)
            ? $this->qrBill->generateReference((string) $invoice->invoice_number, $iban)
            : null;

        return InvoiceCreditor::for($invoice, $reference);
    }

    /**
     * The business logo inlined as a base64 data URI, or null when there is no
     * logo, it is unreadable, or it is not an image we can embed.
     */
    private function logoDataUri(?BusinessEntity $entity): ?string
    {
        $path = (string) $entity?->logo_url;

        if ($path === '') {
            return null;
        }

        try {
            $disk = Storage::disk('public');

            if (! $disk->exists($path) || $disk->size($path) > self::MAX_LOGO_BYTES) {
                return null;
            }

            $mime = (string) $disk->mimeType($path);

            if (! in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
                return null;
            }

            return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($path));
        } catch (Throwable) {
            return null;
        }
    }
}
