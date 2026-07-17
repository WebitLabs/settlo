<?php

namespace App\Models;

use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * Mass-assignable attributes. Security-critical columns (role, status,
     * email_verified_at, remember_token) are intentionally excluded and must
     * only be set explicitly by trusted server code.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'preferred_language',
        'avatar_url',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    /**
     * Default attribute values mirrored from the migration.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'owner',
        'status' => 'pending_verification',
        'preferred_language' => 'en',
    ];

    /**
     * Panel access is default-deny: a user may only enter the panel that
     * matches their role, and never while suspended. During impersonation the
     * authenticated user is the impersonated target, so their role governs.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->status === UserStatus::Suspended) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->role === UserRole::Superadmin,
            'firm' => $this->role === UserRole::Accountant,
            'app' => $this->role === UserRole::Owner,
            default => false,
        };
    }

    public function getFilamentName(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: $this->email;
    }

    public function isSuperadmin(): bool
    {
        return $this->role === UserRole::Superadmin;
    }

    public function isAccountant(): bool
    {
        return $this->role === UserRole::Accountant;
    }

    public function isOwner(): bool
    {
        return $this->role === UserRole::Owner;
    }

    // Relations ----------------------------------------------------------

    /** @return HasMany<BusinessEntity, $this> */
    public function ownedEntities(): HasMany
    {
        return $this->hasMany(BusinessEntity::class, 'owner_id');
    }

    /** @return HasOne<Subscription, $this> */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** @return HasMany<AccountingFirmMember, $this> */
    public function firmMemberships(): HasMany
    {
        return $this->hasMany(AccountingFirmMember::class);
    }

    /** @return HasMany<AiConversation, $this> */
    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    /**
     * Accounting firms this user (an accountant) belongs to.
     *
     * @return BelongsToMany<AccountingFirm, $this>
     */
    public function accountingFirms(): BelongsToMany
    {
        return $this->belongsToMany(AccountingFirm::class, 'accounting_firm_members')
            ->withPivot(['is_owner', 'joined_at'])
            ->withTimestamps();
    }

    // Plan gating ---------------------------------------------------------

    /**
     * The plan features currently available to this user. A trial grants full
     * Pro-tier features on top of the chosen plan (per spec); an expired or
     * cancelled subscription grants nothing.
     *
     * @return list<string>
     */
    public function planFeatures(): array
    {
        $subscription = $this->subscription;

        if (! $subscription || ! $subscription->grantsAccess()) {
            return [];
        }

        $features = $subscription->plan?->features ?? [];

        if ($subscription->status === SubscriptionStatus::Trialing) {
            $proFeatures = Plan::where('code', 'pro')->value('features') ?? [];
            $features = array_values(array_unique([...$features, ...$proFeatures]));
        }

        return $features;
    }

    public function hasFeature(PlanFeature $feature): bool
    {
        if (! config('settlo.enforce_feature_gates', true)) {
            return $this->subscription?->grantsAccess() ?? false;
        }

        return in_array($feature->value, $this->planFeatures(), true);
    }

    /**
     * Whether the account may perform writes. An expired/cancelled-and-ended
     * subscription drops the account into a read-only locked state.
     */
    public function canWrite(): bool
    {
        return $this->subscription?->grantsAccess() ?? false;
    }
}
