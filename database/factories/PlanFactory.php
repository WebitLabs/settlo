<?php

namespace Database\Factories;

use App\Models\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->slug(1),
            'name' => fake()->word(),
            'price_monthly' => fake()->randomElement([19, 49, 99]),
            'currency_code' => 'CHF',
            'trial_days' => 14,
            'human_answers_quota' => 1,
            'features' => [],
            'marketing_features' => [],
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
