<?php

namespace Database\Factories;

use App\Models\PostalCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostalCode>
 */
class PostalCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'postal_code' => (string) fake()->numberBetween(1000, 9999),
            'locality' => fake()->city(),
            'bfs_number' => (string) fake()->numberBetween(1, 6810),
            'canton_code' => fake()->randomElement(['ZH', 'BE', 'AG', 'GE', 'VD']),
            'address_share' => 100,
        ];
    }
}
