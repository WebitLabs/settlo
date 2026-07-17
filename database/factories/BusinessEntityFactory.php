<?php

namespace Database\Factories;

use App\Enums\BusinessEntityType;
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
            'uid' => 'CHE-'.fake()->numerify('###.###.###'),
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

    public function forCanton(string $code): static
    {
        return $this->state(fn () => [
            'canton_id' => Canton::where('code', $code)->value('id'),
        ]);
    }
}
