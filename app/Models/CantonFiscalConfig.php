<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CantonFiscalConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'canton_id', 'year', 'cantonal_rate', 'communal_multiplier_default',
        'church_rate', 'child_deduction', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'cantonal_rate' => 'decimal:4',
            'communal_multiplier_default' => 'decimal:4',
            'church_rate' => 'decimal:4',
            'child_deduction' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /** @return BelongsTo<Canton, $this> */
    public function canton(): BelongsTo
    {
        return $this->belongsTo(Canton::class);
    }
}
