<?php

namespace App\Services\Geo;

/**
 * One address found by the swisstopo address search.
 */
final readonly class AddressSuggestion
{
    public function __construct(
        public string $id,
        public string $street,
        public ?string $streetNumber,
        public string $postalCode,
        public string $city,
        public ?string $cantonCode,
        public ?string $bfsNumber,
        public string $label,
    ) {}

    /**
     * @param  array{id: string, street: string, streetNumber: ?string, postalCode: string, city: string, cantonCode: ?string, bfsNumber: ?string, label: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            street: $data['street'],
            streetNumber: $data['streetNumber'],
            postalCode: $data['postalCode'],
            city: $data['city'],
            cantonCode: $data['cantonCode'],
            bfsNumber: $data['bfsNumber'],
            label: $data['label'],
        );
    }

    /**
     * A plain array, so the suggestion can be cached without serializing objects.
     *
     * @return array{id: string, street: string, streetNumber: ?string, postalCode: string, city: string, cantonCode: ?string, bfsNumber: ?string, label: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'street' => $this->street,
            'streetNumber' => $this->streetNumber,
            'postalCode' => $this->postalCode,
            'city' => $this->city,
            'cantonCode' => $this->cantonCode,
            'bfsNumber' => $this->bfsNumber,
            'label' => $this->label,
        ];
    }
}
