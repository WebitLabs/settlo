<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class VatConfig extends Model
{
    use HasUuids;

    protected $fillable = [
        'year', 'standard_rate', 'reduced_rate', 'special_rate',
        'registration_threshold', 'registration_window_days',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'standard_rate' => 'decimal:3',
            'reduced_rate' => 'decimal:3',
            'special_rate' => 'decimal:3',
            'registration_threshold' => 'integer',
            'registration_window_days' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
