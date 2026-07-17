<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ResidencePermit: string implements HasLabel
{
    case SwissOrCPermit = 'swiss_or_c';
    case BPermit = 'b_permit';

    public function getLabel(): string
    {
        return match ($this) {
            self::SwissOrCPermit => 'Swiss citizen / C permit',
            self::BPermit => 'B permit',
        };
    }

    /**
     * B-permit holders fall under the Quellensteuer (withholding) regime; the
     * tax engine stops and defers to an accountant.
     */
    public function triggersQuellensteuer(): bool
    {
        return $this === self::BPermit;
    }
}
