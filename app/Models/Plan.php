<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\PlanFeature;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code', 'name', 'price_monthly', 'price_yearly', 'currency_code', 'trial_days',
        'human_answers_quota', 'features', 'marketing_features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
            'price_yearly' => 'decimal:2',
            'trial_days' => 'integer',
            'human_answers_quota' => 'integer',
            'features' => 'array',
            'marketing_features' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function grantsFeature(PlanFeature $feature): bool
    {
        return in_array($feature->value, $this->features ?? [], true);
    }

    /**
     * The list price for one billing period of the given interval. Yearly
     * falls back to monthly × the configured multiplier.
     */
    public function priceFor(BillingInterval $interval): string
    {
        return match ($interval) {
            BillingInterval::Month => bcadd((string) $this->price_monthly, '0', 2),
            BillingInterval::Year => $this->price_yearly !== null
                ? bcadd((string) $this->price_yearly, '0', 2)
                : bcmul((string) $this->price_monthly, (string) config('settlo.billing.yearly_multiplier', 10), 2),
        };
    }

    public function stripePriceId(BillingInterval $interval): ?string
    {
        return match ($interval) {
            BillingInterval::Month => $this->stripe_price_monthly_id,
            BillingInterval::Year => $this->stripe_price_yearly_id,
        };
    }
}
