<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountingFirm extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'legal_name', 'uid', 'email', 'phone',
        'street', 'city', 'postal_code', 'logo_url', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<AccountingFirmMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(AccountingFirmMember::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function accountants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'accounting_firm_members')
            ->withPivot(['is_owner', 'joined_at'])
            ->withTimestamps();
    }

    /** @return HasMany<AccountantAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AccountantAssignment::class);
    }

    /** @return HasMany<AccountantAssignment, $this> Active (non-revoked) grants only. */
    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereNull('revoked_at');
    }

    /** @return HasMany<FirmClientInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(FirmClientInvitation::class);
    }
}
