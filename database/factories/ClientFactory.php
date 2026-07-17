<?php

namespace Database\Factories;

use App\Models\BusinessEntity;
use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'business_entity_id' => BusinessEntity::factory(),
            'name' => fake()->company(),
            'email' => fake()->companyEmail(),
            'street' => fake()->streetName(),
            'street_number' => (string) fake()->numberBetween(1, 200),
            'city' => fake()->city(),
            'postal_code' => (string) fake()->numberBetween(1000, 9999),
            'country_code' => 'CH',
            'default_language' => 'en',
            'default_payment_term_days' => 30,
        ];
    }
}
