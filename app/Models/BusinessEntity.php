<?php

namespace App\Models;

use App\Enums\BusinessEntityType;
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
        'name', 'legal_name', 'type', 'uid', 'mwst_number',
        'street', 'street_number', 'city', 'postal_code', 'canton_id',
        'iban', 'default_currency', 'default_payment_term_days',
        'default_language', 'invoice_number_prefix', 'default_invoice_notes', 'logo_url',
    ];

    protected function casts(): array
    {
        return [
            'type' => BusinessEntityType::class,
            'default_payment_term_days' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Canton, $this> */
    public function canton(): BelongsTo
    {
        return $this->belongsTo(Canton::class);
    }

    /** @return HasOne<TaxProfile, $this> */
    public function taxProfile(): HasOne
    {
        return $this->hasOne(TaxProfile::class);
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
}
