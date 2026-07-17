<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Canton extends Model
{
    use HasUuids;

    protected $fillable = [
        'code', 'name_de', 'name_fr', 'name_it', 'name_en', 'capital',
    ];

    /** @return HasMany<CantonFiscalConfig, $this> */
    public function fiscalConfigs(): HasMany
    {
        return $this->hasMany(CantonFiscalConfig::class);
    }

    /** @return HasMany<Commune, $this> */
    public function communes(): HasMany
    {
        return $this->hasMany(Commune::class);
    }

    public function fiscalConfigForYear(int $year): ?CantonFiscalConfig
    {
        return $this->fiscalConfigs()->where('year', $year)->first();
    }
}
