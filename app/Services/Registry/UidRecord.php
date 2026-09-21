<?php

namespace App\Services\Registry;

use App\Enums\BusinessEntityType;

/**
 * An organisation as published by the Swiss UID register (eCH-0108).
 */
final readonly class UidRecord
{
    /**
     * eCH-0108 `vatStatus` "eingetragen" (registered in the VAT register).
     * Codes: 1 = unbekannt, 2 = eingetragen, 3 = nicht eingetragen.
     *
     * @see https://www.ech.ch/sites/default/files/dosvers/hauptdokument/STAN_d_DEF_2020-11-26_eCH-0108_V5.1_Unternehmens-Identifikationsregister.pdf eCH-0108 V5.1, section 3.2.4.1
     */
    public const string VAT_STATUS_ACTIVE = '2';

    /**
     * eCH-0108 `vatEntryStatus` "gelöscht" (the VAT entry was deleted).
     * Codes: 1 = aktiv, 2 = gelöscht.
     *
     * @see https://www.ech.ch/sites/default/files/dosvers/hauptdokument/STAN_d_DEF_2020-11-26_eCH-0108_V5.1_Unternehmens-Identifikationsregister.pdf eCH-0108 V5.1, section 3.2.4.2
     */
    public const string VAT_ENTRY_STATUS_DELETED = '2';

    /**
     * eCH-0097 legal form codes mapped to the business types Settlo knows.
     *
     * @var array<string, BusinessEntityType>
     */
    private const array LEGAL_FORMS = [
        '0101' => BusinessEntityType::SoleProprietorship,
        '0106' => BusinessEntityType::AG,
        '0107' => BusinessEntityType::GmbH,
    ];

    public function __construct(
        public string $uid,
        public string $name,
        public ?string $legalName = null,
        public ?string $legalForm = null,
        public ?string $street = null,
        public ?string $houseNumber = null,
        public ?string $postalCode = null,
        public ?string $town = null,
        public ?string $bfsNumber = null,
        public ?string $cantonCode = null,
        public ?string $vatStatus = null,
        public ?string $vatUid = null,
        public bool $vatLiquidated = false,
        public ?string $vatEntryStatus = null,
    ) {}

    /**
     * The registered (legal) name, falling back to the organisation name.
     */
    public function registeredName(): string
    {
        return filled($this->legalName) ? $this->legalName : $this->name;
    }

    public function businessType(): ?BusinessEntityType
    {
        return self::LEGAL_FORMS[$this->legalForm] ?? null;
    }

    public function isVatActive(): bool
    {
        return $this->vatStatus === self::VAT_STATUS_ACTIVE
            && $this->vatEntryStatus !== self::VAT_ENTRY_STATUS_DELETED
            && filled($this->vatUid)
            && ! $this->vatLiquidated;
    }

    /**
     * "CHE-123.456.789 MWST" when the organisation has an active VAT registration.
     */
    public function vatNumber(): ?string
    {
        return $this->isVatActive() ? $this->vatUid.' MWST' : null;
    }

    /**
     * @return array<string, string|bool|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * @param  array<string, string|bool|null>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(...$data);
    }
}
