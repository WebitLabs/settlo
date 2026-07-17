<?php

namespace App\Models;

use App\Enums\PlanFeature;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code', 'name', 'price_monthly', 'currency_code', 'trial_days',
        'human_answers_quota', 'features', 'marketing_features', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_monthly' => 'decimal:2',
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
}
