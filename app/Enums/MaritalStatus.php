<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaritalStatus: string implements HasLabel
{
    case Single = 'single';
    case Married = 'married';
    case SingleParent = 'single_parent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Single => 'Single (Tariff A)',
            self::Married => 'Married (Tariff B)',
            self::SingleParent => 'Single parent (Tariff H)',
        };
    }

    /**
     * Federal tax tariff letter. Married and single-parent both use Tariff B
     * brackets (Tariff H approximates B for the engine).
     */
    public function tariff(): string
    {
        return $this === self::Single ? 'A' : 'B';
    }
}
