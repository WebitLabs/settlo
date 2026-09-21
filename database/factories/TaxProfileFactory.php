<?php

namespace Database\Factories;

use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Models\Canton;
use App\Models\TaxProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxProfile>
 */
class TaxProfileFactory extends Factory
{
    protected $model = TaxProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->owner(),
            'canton_id' => fn () => Canton::query()->inRandomOrder()->value('id'),
            'marital_status' => MaritalStatus::Single,
            'number_of_children' => 0,
            'residence_permit' => ResidencePermit::SwissCitizen,
            'pillar3a_amount' => 0,
            'has_pillar2' => false,
            'kirchensteuer' => false,
            'birth_year' => fake()->numberBetween(1965, 2000),
            'employment_income' => 0,
            'other_income' => 0,
        ];
    }

    public function forCanton(string $code): static
    {
        return $this->state(fn () => [
            'canton_id' => Canton::where('code', $code)->value('id'),
        ]);
    }
}
