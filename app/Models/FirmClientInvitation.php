<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmClientInvitation extends Model
{
    use HasUuids;

    /**
     * token_hash / accepted_* are set only by trusted server code, never mass
     * assigned from a request.
     *
     * @var list<string>
     */
    protected $fillable = [
        'accounting_firm_id', 'invited_by_id', 'email', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<AccountingFirm, $this> */
    public function accountingFirm(): BelongsTo
    {
        return $this->belongsTo(AccountingFirm::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isPast();
    }
}
