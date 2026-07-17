<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use Sprain\SwissQrBill\DataGroup\Element\CreditorInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentAmountInformation;
use Sprain\SwissQrBill\DataGroup\Element\PaymentReference;
use Sprain\SwissQrBill\DataGroup\Element\StructuredAddress;
use Sprain\SwissQrBill\PaymentPart\Output\DisplayOptions;
use Sprain\SwissQrBill\PaymentPart\Output\HtmlOutput\HtmlOutput;
use Sprain\SwissQrBill\QrBill;
use Sprain\SwissQrBill\QrCode\QrCode;
use Sprain\SwissQrBill\Reference\QrPaymentReferenceGenerator;
use Sprain\SwissQrBill\Reference\RfCreditorReferenceGenerator;
use Throwable;

/**
 * Builds Swiss QR-bill artifacts (reference, QR code, payment part) for invoices.
 *
 * The reference type follows the creditor IBAN: a QR-IBAN (IID 30000–31999)
 * carries a 27-digit QRR reference; a normal IBAN carries an ISO-11649 SCOR
 * creditor reference. This guarantees the produced QR bill always validates.
 */
class QrBillService
{
    public function referenceType(?string $iban): string
    {
        $iban = preg_replace('/\s+/', '', (string) $iban) ?? '';
        $iid = (int) substr($iban, 4, 5);

        return ($iid >= 30000 && $iid <= 31999)
            ? PaymentReference::TYPE_QR
            : PaymentReference::TYPE_SCOR;
    }

    /**
     * Reference matching the IBAN type, derived from the invoice number.
     */
    public function generateReference(string $invoiceNumber, ?string $iban): string
    {
        $digits = preg_replace('/\D/', '', $invoiceNumber) ?? '';
        $base = ltrim($digits, '0');
        if ($base === '') {
            $base = (string) random_int(1, 999999);
        }

        if ($this->referenceType($iban) === PaymentReference::TYPE_QR) {
            return QrPaymentReferenceGenerator::generate(null, substr($base, -26));
        }

        return RfCreditorReferenceGenerator::generate(substr($base, -21));
    }

    /**
     * Build a validated QrBill from a sent invoice's frozen creditor snapshot,
     * or null if the data cannot form a valid Swiss QR bill.
     */
    public function buildFor(Invoice $invoice): ?QrBill
    {
        try {
            $qrBill = QrBill::create();

            $qrBill->setCreditor(StructuredAddress::createWithStreet(
                mb_substr((string) ($invoice->creditor_name ?: 'Creditor'), 0, 70),
                mb_substr((string) ($invoice->creditor_street ?: 'n/a'), 0, 70),
                null,
                mb_substr((string) $invoice->creditor_postal, 0, 16),
                mb_substr((string) $invoice->creditor_city, 0, 35),
                $invoice->creditor_country ?: 'CH',
            ));

            $qrBill->setCreditorInformation(CreditorInformation::create(
                preg_replace('/\s+/', '', (string) $invoice->creditor_iban) ?? '',
            ));

            $qrBill->setPaymentAmountInformation(PaymentAmountInformation::create(
                $invoice->currency_code ?: 'CHF',
                (float) $invoice->total,
            ));

            if (! empty($invoice->qr_reference)) {
                $qrBill->setPaymentReference(PaymentReference::create(
                    $this->referenceType($invoice->creditor_iban),
                    $invoice->qr_reference,
                ));
            }

            $client = $invoice->client;
            if ($client !== null && filled($client->postal_code) && filled($client->city)) {
                $qrBill->setUltimateDebtor(StructuredAddress::createWithStreet(
                    mb_substr((string) $client->name, 0, 70),
                    mb_substr((string) ($client->street ?: 'n/a'), 0, 70),
                    $client->street_number ? mb_substr((string) $client->street_number, 0, 16) : null,
                    mb_substr((string) $client->postal_code, 0, 16),
                    mb_substr((string) $client->city, 0, 35),
                    $client->country_code ?: 'CH',
                ));
            }

            return $qrBill->getViolations()->count() === 0 ? $qrBill : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The Swiss payment part as embeddable HTML, or null if it can't be built.
     */
    public function paymentPartHtml(Invoice $invoice, string $language = 'en'): ?string
    {
        $qrBill = $this->buildFor($invoice);
        if ($qrBill === null) {
            return null;
        }

        try {
            $options = (new DisplayOptions)->setPrintable(false);

            return (new HtmlOutput($qrBill, $language))
                ->setDisplayOptions($options)
                ->getPaymentPart();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The QR code alone as an SVG data URI, or null if it can't be built.
     */
    public function qrCodeDataUri(Invoice $invoice): ?string
    {
        $qrBill = $this->buildFor($invoice);
        if ($qrBill === null) {
            return null;
        }

        try {
            return $qrBill->getQrCode()->getDataUri(QrCode::FILE_FORMAT_SVG);
        } catch (Throwable) {
            return null;
        }
    }
}
