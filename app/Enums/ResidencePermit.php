<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The user's residence status (shown as "Residence status" in the UI).
 */
enum ResidencePermit: string implements HasLabel
{
    case SwissCitizen = 'swiss';
    case EuEftaPermitB = 'eu_efta_b';
    case EuEftaPermitC = 'eu_efta_c';
    case EuEftaPermitL = 'eu_efta_l';
    case NonEuPermitB = 'non_eu_b';
    case NonEuPermitC = 'non_eu_c';
    case NonEuPermitL = 'non_eu_l';
    case CrossBorderPermitG = 'cross_border_g';

    public const string QUELLENSTEUER_WARNING = 'With this residence status your income tax is usually withheld at source (Quellensteuer). Settlo doesn\'t estimate income tax for it — your accountant will.';

    public function getLabel(): string
    {
        return match ($this) {
            self::SwissCitizen => '🇨🇭 Swiss citizen',
            self::EuEftaPermitB => '🇪🇺 EU/EFTA citizen – Permit B',
            self::EuEftaPermitC => '🇪🇺 EU/EFTA citizen – Permit C',
            self::EuEftaPermitL => '🇪🇺 EU/EFTA citizen – Permit L',
            self::NonEuPermitB => '🌍 Non-EU/EFTA – Permit B',
            self::NonEuPermitC => '🌍 Non-EU/EFTA – Permit C',
            self::NonEuPermitL => '🌍 Non-EU/EFTA – Permit L',
            self::CrossBorderPermitG => 'Cross-border commuter (Permit G)',
        };
    }

    /**
     * B, L and G permit holders fall under the Quellensteuer (withholding)
     * regime; the tax engine stops and defers to an accountant.
     */
    public function triggersQuellensteuer(): bool
    {
        return match ($this) {
            self::SwissCitizen, self::EuEftaPermitC, self::NonEuPermitC => false,
            default => true,
        };
    }
}
