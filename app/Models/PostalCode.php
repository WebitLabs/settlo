<?php

namespace App\Models;

use Database\Factories\PostalCodeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One locality of a Swiss (or Liechtenstein) postal code from swisstopo's
 * official directory. A postal code can span several localities and communes;
 * address_share is the percentage of the code's addresses in that commune.
 * Liechtenstein rows have no canton code.
 */
class PostalCode extends Model
{
    /** @use HasFactory<PostalCodeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'postal_code', 'locality', 'bfs_number', 'canton_code', 'address_share',
    ];

    protected function casts(): array
    {
        return [
            'address_share' => 'decimal:2',
        ];
    }

    /**
     * The locality holding most of the postal code's addresses.
     */
    public static function bestMatch(string $postalCode): ?self
    {
        return self::query()
            ->where('postal_code', trim($postalCode))
            ->orderByDesc('address_share')
            ->orderBy('locality')
            ->first();
    }
}
