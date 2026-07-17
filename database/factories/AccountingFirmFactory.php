<?php

namespace Database\Factories;

use App\Models\AccountingFirm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingFirm>
 */
class AccountingFirmFactory extends Factory
{
    protected $model = AccountingFirm::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Treuhand AG',
            'uid' => 'CHE-'.fake()->numerify('###.###.###'),
            'email' => fake()->companyEmail(),
            'city' => fake()->city(),
            'postal_code' => (string) fake()->numberBetween(1000, 9999),
            'is_active' => true,
        ];
    }
}
