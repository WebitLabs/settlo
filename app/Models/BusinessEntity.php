<?php

namespace App\Models;

use App\Enums\BusinessEntityType;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Enums\VatStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class BusinessEntity extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * owner_id is intentionally NOT fillable — it is set from the authenticated
     * user server-side, never from request payloads (tenant-hopping guard).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'legal_name', 'type', 'uid', 'mwst_number', 'vat_status', 'estimated_annual_revenue',
        'street', 'street_number', 'city', 'postal_code', 'canton_id',
        'iban', 'default_currency', 'default_payment_term_days',
        'default_language', 'invoice_number_prefix', 'default_invoice_notes', 'logo_url',
    ];

    protected function casts(): array
    {
        return [
            'type' => BusinessEntityType::class,
            'vat_status' => VatStatus::class,
            'estimated_annual_revenue' => 'decimal:2',
            'default_payment_term_days' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * The workspace's own subscription (one per business).
     *
     * @return HasOne<Subscription, $this>
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** @return BelongsTo<Canton, $this> */
    public function canton(): BelongsTo
    {
        return $this->belongsTo(Canton::class);
    }

    /**
     * The owner's personal tax profile (tax profiles belong to the person).
     */
    public function ownerTaxProfile(): ?TaxProfile
    {
        return $this->owner?->taxProfile;
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<TaxEstimation, $this> */
    public function taxEstimations(): HasMany
    {
        return $this->hasMany(TaxEstimation::class);
    }

    /** @return HasMany<BankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /** @return HasMany<AiConversation, $this> */
    public function aiConversations(): HasMany
    {
        return $this->hasMany(AiConversation::class);
    }

    /** @return HasMany<AccountantAssignment, $this> */
    public function accountantAssignments(): HasMany
    {
        return $this->hasMany(AccountantAssignment::class);
    }

    public function latestTaxEstimation(?int $fiscalYear = null): ?TaxEstimation
    {
        return $this->taxEstimations()
            ->when($fiscalYear, fn ($q) => $q->where('fiscal_year', $fiscalYear))
            ->latest('calculated_at')
            ->first();
    }

    /**
     * Whether invoices of this business may carry Swiss VAT: the business is
     * registered, or a VAT (MWST) number is on file.
     */
    public function isVatRegistered(): bool
    {
        return ($this->vat_status?->isRegistered() ?? false) || filled($this->mwst_number);
    }

    public function isSoleProprietorship(): bool
    {
        return $this->type === BusinessEntityType::SoleProprietorship;
    }

    // Plan gating ---------------------------------------------------------

    /**
     * The plan features available in this workspace. A trial grants full
     * Pro-tier features on top of the chosen plan (per spec); a subscription
     * that does not grant access grants nothing.
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
            return $this->canWrite();
        }

        return in_array($feature->value, $this->planFeatures(), true);
    }

    /**
     * Whether the workspace may be written to. An expired/cancelled workspace
     * is read-only; an incomplete one (checkout pending) is locked.
     */
    public function canWrite(): bool
    {
        return $this->subscription?->grantsAccess() ?? false;
    }
}
