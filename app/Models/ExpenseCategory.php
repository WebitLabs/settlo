<?php

namespace App\Models;

use App\Enums\DeductibilityStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExpenseCategory extends Model
{
    use HasUuids;

    protected $fillable = [
        'code', 'name_de', 'name_fr', 'name_it', 'name_en',
        'default_deductibility', 'default_deductible_pct', 'requires_proof',
        'vat_eligible', 'legal_basis', 'notes', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'default_deductibility' => DeductibilityStatus::class,
            'default_deductible_pct' => 'decimal:2',
            'requires_proof' => 'boolean',
            'vat_eligible' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    public function localizedName(string $language = 'en'): string
    {
        return $this->{'name_'.$language} ?? $this->name_en;
    }
}
