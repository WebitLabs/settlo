<?php

namespace Database\Factories;

use App\Enums\BusinessEntityType;
use App\Enums\VatStatus;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessEntity>
 */
class BusinessEntityFactory extends Factory
{
    protected $model = BusinessEntity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory()->owner(),
            'name' => fake()->company(),
            'type' => BusinessEntityType::SoleProprietorship,
            'uid' => self::validUid(),
            'vat_status' => VatStatus::NotRegistered,
            'street' => fake()->streetName(),
            'street_number' => (string) fake()->numberBetween(1, 200),
            'city' => fake()->city(),
            'postal_code' => (string) fake()->numberBetween(1000, 9999),
            'canton_id' => fn () => Canton::query()->inRandomOrder()->value('id'),
            'iban' => 'CH'.fake()->numerify('## #### #### #### #### #'),
            'default_currency' => 'CHF',
            'default_payment_term_days' => 30,
            'default_language' => 'en',
            'invoice_number_prefix' => 'INV-',
        ];
    }

    /**
     * A random UID with a correct eCH-0097 check digit.
     */
    public static function validUid(): string
    {
        $weights = [5, 4, 3, 2, 7, 6, 5, 4];

        do {
            $digits = array_map(fn (): int => fake()->numberBetween(0, 9), range(1, 8));
            $sum = array_sum(array_map(fn (int $digit, int $weight): int => $digit * $weight, $digits, $weights));
            $check = (11 - ($sum % 11)) % 11;
        } while ($check === 10);

        $number = implode('', $digits).$check;

        return sprintf('CHE-%s.%s.%s', substr($number, 0, 3), substr($number, 3, 3), substr($number, 6, 3));
    }

    public function forCanton(string $code): static
    {
        return $this->state(fn () => [
            'canton_id' => Canton::where('code', $code)->value('id'),
        ]);
    }

    /**
     * A business with a VAT (MWST) number on file, so its invoices may carry VAT.
     */
    public function vatRegistered(): static
    {
        return $this->state(fn () => [
            'vat_status' => VatStatus::RegisteredVoluntary,
            'mwst_number' => 'CHE-148.830.302 MWST',
        ]);
    }
}
