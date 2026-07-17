<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'bank_name', 'iban', 'account_name', 'currency_code', 'is_default', 'last_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'last_sync_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BusinessEntity, $this> */
    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class);
    }
}
