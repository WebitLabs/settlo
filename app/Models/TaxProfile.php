<?php

namespace App\Models;

use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Enums\VatStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxProfile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'business_entity_id', 'canton_id', 'commune_id', 'vat_status',
        'estimated_annual_revenue', 'marital_status', 'number_of_children',
        'residence_permit', 'pillar3a_amount', 'has_pillar2', 'kirchensteuer',
        'birth_year', 'employment_income', 'employment_rate',
        'employment_taxed_at_source', 'other_income',
    ];

    protected function casts(): array
    {
        return [
            'vat_status' => VatStatus::class,
            'marital_status' => MaritalStatus::class,
            'residence_permit' => ResidencePermit::class,
            'estimated_annual_revenue' => 'decimal:2',
            'number_of_children' => 'integer',
            'pillar3a_amount' => 'decimal:2',
            'has_pillar2' => 'boolean',
            'kirchensteuer' => 'boolean',
            'birth_year' => 'integer',
            'employment_income' => 'decimal:2',
            'employment_rate' => 'decimal:2',
            'employment_taxed_at_source' => 'boolean',
            'other_income' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<BusinessEntity, $this> */
    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class);
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

    public function age(?int $atYear = null): ?int
    {
        if ($this->birth_year === null) {
            return null;
        }

        return ($atYear ?? (int) date('Y')) - $this->birth_year;
    }
}
