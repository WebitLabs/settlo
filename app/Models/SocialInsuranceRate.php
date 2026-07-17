<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SocialInsuranceRate extends Model
{
    use HasUuids;

    protected $fillable = [
        'year', 'ahv_rate', 'iv_rate', 'eo_rate', 'pillar3a_max_se',
        'pillar3a_max_with_p2', 'ahv_minimum', 'age_exemption_amount',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'ahv_rate' => 'decimal:3',
            'iv_rate' => 'decimal:3',
            'eo_rate' => 'decimal:3',
            'pillar3a_max_se' => 'integer',
            'pillar3a_max_with_p2' => 'integer',
            'ahv_minimum' => 'integer',
            'age_exemption_amount' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
