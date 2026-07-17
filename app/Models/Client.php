<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * business_entity_id is set from the active tenant server-side, not from
     * the form payload.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name', 'email', 'phone', 'vat_number',
        'street', 'street_number', 'city', 'postal_code', 'country_code',
        'default_language', 'default_payment_term_days', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'default_payment_term_days' => 'integer',
        ];
    }

    /** @return BelongsTo<BusinessEntity, $this> */
    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function fullAddress(): string
    {
        return trim(implode(', ', array_filter([
            trim("{$this->street} {$this->street_number}"),
            trim("{$this->postal_code} {$this->city}"),
        ])));
    }
}
