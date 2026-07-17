<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use HasFactory, HasUuids;

    /**
     * status / quota counters / gateway ids are lifecycle-managed by the
     * billing service, never mass assigned from a form.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id', 'plan_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_starts_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'trial_used' => 'boolean',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'canceled_at' => 'datetime',
            'human_answers_used' => 'integer',
            'human_answers_quota' => 'integer',
            'quota_reset_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Plan, $this> */
    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'pending_plan_id');
    }

    /** @return HasMany<SubscriptionPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function grantsAccess(): bool
    {
        return $this->status instanceof SubscriptionStatus && $this->status->grantsAccess();
    }

    public function humanAnswersRemaining(): int
    {
        return max(0, $this->human_answers_quota - $this->human_answers_used);
    }

    public function hasHumanAnswersRemaining(): bool
    {
        return $this->humanAnswersRemaining() > 0;
    }
}
