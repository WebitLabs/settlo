<?php

namespace Database\Factories;

use App\Enums\DeductibilityStatus;
use App\Enums\ExpenseStatus;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 20, 2000);
        $vatRate = 8.1;
        $net = round($amount / (1 + $vatRate / 100), 2);

        return [
            'business_entity_id' => BusinessEntity::factory(),
            'status' => ExpenseStatus::Reviewed,
            'vendor' => fake()->company(),
            'description' => fake()->sentence(3),
            'amount' => $amount,
            'vat_amount' => round($amount - $net, 2),
            'vat_rate' => $vatRate,
            'net_amount' => $net,
            'currency_code' => 'CHF',
            'expense_date' => fake()->dateTimeBetween('-6 months', 'now'),
            'category_id' => fn () => ExpenseCategory::query()->inRandomOrder()->value('id'),
            'deductibility' => DeductibilityStatus::FullyDeductible,
            'deductible_pct' => 100,
            'deductible_amount' => $amount,
        ];
    }

    public function pendingReview(): static
    {
        return $this->state(fn () => [
            'status' => ExpenseStatus::PendingReview,
            'deductibility' => DeductibilityStatus::Uncertain,
            'deductible_pct' => null,
            'deductible_amount' => null,
        ]);
    }
}
