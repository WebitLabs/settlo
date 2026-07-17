<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FederalTaxBracket extends Model
{
    use HasUuids;

    protected $fillable = [
        'year', 'tariff', 'bracket_from', 'bracket_to', 'rate', 'base_amount',
        'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'bracket_from' => 'integer',
            'bracket_to' => 'integer',
            'rate' => 'decimal:3',
            'base_amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }
}
