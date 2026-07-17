<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum DeductibilityStatus: string implements HasColor, HasLabel
{
    case FullyDeductible = 'fully_deductible';
    case PartiallyDeductible = 'partially_deductible';
    case NotDeductible = 'not_deductible';
    case Uncertain = 'uncertain';

    public function getLabel(): string
    {
        return match ($this) {
            self::FullyDeductible => '100% deductible',
            self::PartiallyDeductible => 'Partially deductible',
            self::NotDeductible => 'Not deductible',
            self::Uncertain => 'Review needed',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::FullyDeductible => 'success',
            self::PartiallyDeductible => 'warning',
            self::NotDeductible => 'gray',
            self::Uncertain => 'warning',
        };
    }

    public function defaultPercent(): ?float
    {
        return match ($this) {
            self::FullyDeductible => 100.0,
            self::PartiallyDeductible => 50.0,
            self::NotDeductible => 0.0,
            self::Uncertain => null,
        };
    }
}
