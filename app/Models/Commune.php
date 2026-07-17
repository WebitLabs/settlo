<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Commune extends Model
{
    use HasUuids;

    protected $fillable = [
        'canton_id', 'name', 'bfs_number', 'tax_multiplier', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'tax_multiplier' => 'decimal:4',
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
