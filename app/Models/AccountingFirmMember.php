<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingFirmMember extends Model
{
    use HasUuids;

    protected $fillable = [
        'accounting_firm_id', 'user_id', 'is_owner', 'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AccountingFirm, $this> */
    public function accountingFirm(): BelongsTo
    {
        return $this->belongsTo(AccountingFirm::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
