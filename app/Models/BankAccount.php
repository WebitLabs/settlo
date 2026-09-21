<?php

namespace App\Models;

use App\Rules\ValidIban;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class BankAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'bank_name', 'iban', 'account_name', 'currency_code', 'is_default', 'last_sync_at',
    ];

    /**
     * Defence in depth: whatever path writes a bank account, the IBAN is stored
     * normalized and must be a valid CH/LI IBAN.
     */
    protected static function booted(): void
    {
        static::saving(function (BankAccount $account): void {
            $account->iban = ValidIban::normalize((string) $account->iban);

            if (! ValidIban::isValid($account->iban)) {
                throw new InvalidArgumentException('A bank account needs a valid Swiss (CH) or Liechtenstein (LI) IBAN.');
            }
        });
    }

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

    /** @param  Builder<BankAccount>  $query */
    public function scopeIsDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    /**
     * The IBAN of the business' default bank account — the account a Swiss
     * QR-bill is paid into — or null when no account is marked as default.
     */
    public static function defaultIbanFor(BusinessEntity $entity): ?string
    {
        return $entity->bankAccounts()
            ->isDefault()
            ->orderBy('created_at')
            ->value('iban');
    }
}
