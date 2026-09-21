<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BillingInterval: string implements HasLabel
{
    case Month = 'month';
    case Year = 'year';

    public function getLabel(): string
    {
        return match ($this) {
            self::Month => 'Monthly',
            self::Year => 'Yearly (2 months free)',
        };
    }
}
