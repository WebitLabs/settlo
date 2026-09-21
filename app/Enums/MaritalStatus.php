<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MaritalStatus: string implements HasLabel
{
    case Single = 'single';
    case Married = 'married';
    case MarriedDualIncome = 'married_dual_income';
    case SingleParent = 'single_parent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Single => 'Single (Tariff A)',
            self::Married => 'Married, single income (Tariff B)',
            self::MarriedDualIncome => 'Married, dual income (Tariff C)',
            self::SingleParent => 'Single parent (Tariff H)',
        };
    }

    /**
     * Federal tax tariff letter. Federal income tax only knows the single and
     * married schedules, so every non-single status (including the withholding
     * tariffs C and H) uses the Tariff B brackets.
     */
    public function tariff(): string
    {
        return $this === self::Single ? 'A' : 'B';
    }
}
