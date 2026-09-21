<?php

namespace App\Models;

use App\Enums\BusinessEntityType;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasName;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Cashier\Billable;

class User extends Authenticatable implements FilamentUser, HasDefaultTenant, HasName, HasTenants, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable, SoftDeletes;

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
        'street',
        'street_number',
        'postal_code',
        'city',
        'country_code',
        'canton_id',
        'commune_id',
        'phone_country',
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
            'phone_verified_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'trial_used_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'privacy_acknowledged_at' => 'datetime',
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
        'country_code' => 'CH',
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
            'app', 'workspace' => $this->role === UserRole::Owner,
            default => false,
        };
    }

    public function getFilamentName(): string
    {
        return trim("{$this->first_name} {$this->last_name}") ?: $this->email;
    }

    /**
     * The tenants selectable in a panel. The workspace panel is scoped to the
     * businesses this owner owns; the firm panel to the accounting firms this
     * accountant belongs to. The personal app panel has no tenants.
     *
     * @return Collection<int, Model>
     */
    public function getTenants(Panel $panel): Collection
    {
        return match ($panel->getId()) {
            'workspace' => $this->ownedEntities()->orderBy('name')->get(),
            'firm' => $this->accountingFirms()->get(),
            default => collect(),
        };
    }

    /**
     * The tenant opened when a panel is entered without one: the last opened
     * workspace (falling back to the oldest business), or the first firm.
     */
    public function getDefaultTenant(Panel $panel): ?Model
    {
        return match ($panel->getId()) {
            'workspace' => $this->lastBusinessEntity()->where('owner_id', $this->getKey())->first()
                ?? $this->ownedEntities()->oldest()->first(),
            'firm' => $this->accountingFirms()->first(),
            default => null,
        };
    }

    /**
     * Default-deny cross-tenant guard: an owner may enter only a business they
     * own, and an accountant only a firm they are a member of. This is the hard
     * boundary that prevents tenant-hopping via a crafted URL.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        if ($tenant instanceof BusinessEntity) {
            return $this->role === UserRole::Owner
                && $tenant->owner_id === $this->getKey();
        }

        if ($tenant instanceof AccountingFirm) {
            return $this->role === UserRole::Accountant
                && $this->accountingFirms()->whereKey($tenant->getKey())->exists();
        }

        return false;
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

    /**
     * The owner's businesses that are taxed on the person (sole proprietorships).
     *
     * @return HasMany<BusinessEntity, $this>
     */
    public function soleProprietorships(): HasMany
    {
        return $this->ownedEntities()->where('type', BusinessEntityType::SoleProprietorship);
    }

    /** @return BelongsTo<BusinessEntity, $this> */
    public function lastBusinessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class, 'last_business_entity_id');
    }

    /** @return HasOne<TaxProfile, $this> */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(TaxProfile::class);
    }

    /** @return BelongsTo<Canton, $this> */
    public function canton(): BelongsTo
    {
        return $this->belongsTo(Canton::class);
    }

    /** @return BelongsTo<Commune, $this> */
    public function commune(): BelongsTo
    {
        return $this->belongsTo(Commune::class);
    }

    /** @return HasMany<TaxEstimation, $this> */
    public function taxEstimations(): HasMany
    {
        return $this->hasMany(TaxEstimation::class);
    }

    /**
     * The latest consolidated (personal) tax estimation, i.e. the snapshot that
     * is not tied to a single business.
     */
    public function personalTaxEstimation(?int $fiscalYear = null): ?TaxEstimation
    {
        return $this->taxEstimations()
            ->whereNull('business_entity_id')
            ->when($fiscalYear, fn ($query) => $query->where('fiscal_year', $fiscalYear))
            ->latest('calculated_at')
            ->first();
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

    // Billing -------------------------------------------------------------

    /**
     * The owner's per-workspace (domain) subscriptions. Cashier's own
     * `subscription()` / `subscriptions()` refer to the Stripe mirror.
     *
     * @return HasMany<Subscription, $this>
     */
    public function workspaceSubscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function hasFeatureInAnyWorkspace(PlanFeature $feature): bool
    {
        return $this->ownedEntities()
            ->with('subscription.plan')
            ->get()
            ->contains(fn (BusinessEntity $entity): bool => $entity->hasFeature($feature));
    }

    /**
     * The number of workspace subscriptions that count towards the
     * multi-business discount (trialing, active or past due).
     */
    public function activeWorkspaceSubscriptionCount(?BusinessEntity $except = null): int
    {
        return $this->workspaceSubscriptions()
            ->whereIn('status', [
                SubscriptionStatus::Trialing->value,
                SubscriptionStatus::Active->value,
                SubscriptionStatus::PastDue->value,
            ])
            ->when($except, fn ($query) => $query->where('business_entity_id', '!=', $except->getKey()))
            ->count();
    }

    /**
     * Whether the owner already had the one free trial.
     */
    public function hasUsedTrial(): bool
    {
        return $this->trial_used_at !== null;
    }
}
