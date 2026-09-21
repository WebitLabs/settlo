<?php

namespace App\Services\Invoicing;

use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\Invoice;

/**
 * Who the invoice says it is from.
 *
 * An issued invoice is a legal document: it must keep rendering the creditor
 * exactly as it was frozen at send time (name, address, UID, VAT registration,
 * IBAN), whatever the business looks like today. The live business entity is
 * only consulted for a draft, which has no snapshot yet and is never handed to
 * a client as-is.
 */
final readonly class InvoiceCreditor
{
    public function __construct(
        public ?string $name = null,
        public ?string $legalName = null,
        public ?string $street = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public string $country = 'CH',
        public ?string $uid = null,
        public ?string $vatNumber = null,
        public bool $vatRegistered = false,
        public ?string $iban = null,
        public ?string $reference = null,
        public bool $frozen = false,
    ) {}

    /**
     * The frozen snapshot of an issued invoice, or the live business entity for
     * a draft. `$reference` supplies a provisional QR reference for a draft
     * preview; an issued invoice always uses its own stored reference.
     */
    public static function for(Invoice $invoice, ?string $reference = null): self
    {
        if (filled($invoice->creditor_name)) {
            return new self(
                name: $invoice->creditor_name,
                legalName: $invoice->creditor_legal_name,
                street: $invoice->creditor_street,
                postalCode: $invoice->creditor_postal,
                city: $invoice->creditor_city,
                country: $invoice->creditor_country ?: 'CH',
                uid: $invoice->creditor_uid,
                vatNumber: $invoice->creditor_vat_number,
                vatRegistered: (bool) $invoice->creditor_vat_registered,
                iban: $invoice->creditor_iban,
                reference: $invoice->qr_reference,
                frozen: true,
            );
        }

        $entity = $invoice->businessEntity;

        return $entity instanceof BusinessEntity
            ? self::fromEntity($entity, $reference)
            : new self(reference: $reference);
    }

    /**
     * The live creditor of a business: its current identity plus the IBAN of
     * its default bank account (falling back to the invoicing IBAN).
     */
    public static function fromEntity(BusinessEntity $entity, ?string $reference = null): self
    {
        return new self(
            name: $entity->name,
            legalName: $entity->legal_name,
            street: trim("{$entity->street} {$entity->street_number}") ?: null,
            postalCode: $entity->postal_code,
            city: $entity->city,
            country: 'CH',
            uid: $entity->uid,
            vatNumber: $entity->mwst_number,
            vatRegistered: $entity->isVatRegistered(),
            iban: self::ibanFor($entity),
            reference: $reference,
            frozen: false,
        );
    }

    /**
     * The account an invoice of this business is paid into: the default bank
     * account, or the IBAN stored in the invoicing settings.
     */
    public static function ibanFor(BusinessEntity $entity): ?string
    {
        $iban = BankAccount::defaultIbanFor($entity) ?: $entity->iban;

        return filled($iban) ? (string) preg_replace('/\s+/', '', (string) $iban) : null;
    }

    /**
     * The snapshot columns written to the invoice when it is issued.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'creditor_iban' => $this->iban,
            'creditor_name' => $this->name,
            'creditor_legal_name' => $this->legalName,
            'creditor_street' => $this->street,
            'creditor_city' => $this->city,
            'creditor_postal' => $this->postalCode,
            'creditor_country' => $this->country,
            'creditor_uid' => $this->uid,
            'creditor_vat_number' => $this->vatNumber,
            'creditor_vat_registered' => $this->vatRegistered,
        ];
    }

    /**
     * "Street, 8001 Zürich" — the address as it is printed on the document.
     */
    public function addressLines(): string
    {
        return trim(implode(', ', array_filter([
            $this->street,
            trim("{$this->postalCode} {$this->city}"),
        ])));
    }
}
