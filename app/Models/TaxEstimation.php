<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable snapshot of a tax calculation. Historical rows are never
 * recalculated in place — a new row is written on each recalculation and the
 * rates used are frozen into rates_snapshot.
 */
class TaxEstimation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'calculated_at' => 'datetime',
            'gross_revenue' => 'decimal:2',
            'total_expenses' => 'decimal:2',
            'net_income' => 'decimal:2',
            'ahv_contribution' => 'decimal:2',
            'iv_contribution' => 'decimal:2',
            'eo_contribution' => 'decimal:2',
            'total_social_insurance' => 'decimal:2',
            'ahv_deduction' => 'decimal:2',
            'taxable_income' => 'decimal:2',
            'federal_tax' => 'decimal:2',
            'cantonal_tax' => 'decimal:2',
            'communal_tax' => 'decimal:2',
            'church_tax' => 'decimal:2',
            'total_income_tax' => 'decimal:2',
            'total_tax_burden' => 'decimal:2',
            'monthly_reserve' => 'decimal:2',
            'effective_rate' => 'decimal:2',
            'projected_annual_revenue' => 'decimal:2',
            'projected_total_tax' => 'decimal:2',
            'vat_threshold_pct' => 'decimal:2',
            'vat_crossing_date' => 'date',
            'quellensteuer_regime' => 'boolean',
            'inputs' => 'array',
            'rates_snapshot' => 'array',
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
}
