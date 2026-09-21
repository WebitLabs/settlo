<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->owner(),
            'business_entity_id' => fn (array $attributes) => BusinessEntity::factory()
                ->create(['owner_id' => $attributes['user_id']])
                ->getKey(),
            'plan_id' => fn () => Plan::where('code', 'pro')->value('id') ?? Plan::factory(),
            'status' => SubscriptionStatus::Trialing,
            'trial_starts_at' => now(),
            'trial_ends_at' => now()->addDays(14),
            'human_answers_used' => 0,
            'human_answers_quota' => 1,
            'quota_reset_at' => now()->addMonth()->startOfMonth(),
            'billing_interval' => BillingInterval::Month,
            'discount_percent' => 0,
            'gateway' => 'dummy',
        ];
    }

    /**
     * The subscription of an existing workspace (owned by its owner).
     */
    public function forEntity(BusinessEntity $entity): static
    {
        return $this->state(fn () => [
            'business_entity_id' => $entity->getKey(),
            'user_id' => $entity->owner_id,
        ]);
    }

    /**
     * Checkout not completed yet — the workspace is locked.
     */
    public function incomplete(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Incomplete,
            'trial_starts_at' => null,
            'trial_ends_at' => null,
            'human_answers_quota' => 0,
        ]);
    }

    public function yearly(): static
    {
        return $this->state(fn () => [
            'billing_interval' => BillingInterval::Year,
        ]);
    }

    public function onPlan(string $code, int $quota): static
    {
        return $this->state(fn () => [
            'plan_id' => Plan::where('code', $code)->value('id'),
            'human_answers_quota' => $quota,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Expired,
            'trial_ends_at' => now()->subDay(),
        ]);
    }
}
