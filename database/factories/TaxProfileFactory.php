<?php

namespace Database\Factories;

use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Enums\VatStatus;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\TaxProfile;
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
            'business_entity_id' => BusinessEntity::factory(),
            'canton_id' => fn () => Canton::query()->inRandomOrder()->value('id'),
            'vat_status' => VatStatus::NotRegistered,
            'marital_status' => MaritalStatus::Single,
            'number_of_children' => 0,
            'residence_permit' => ResidencePermit::SwissOrCPermit,
            'pillar3a_amount' => 0,
            'has_pillar2' => false,
            'kirchensteuer' => false,
            'birth_year' => fake()->numberBetween(1965, 2000),
            'employment_income' => 0,
            'other_income' => 0,
        ];
    }
}
