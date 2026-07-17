<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum BusinessEntityType: string implements HasLabel
{
    case SoleProprietorship = 'sole_proprietorship';
    case GmbH = 'gmbh';
    case AG = 'ag';
    case Association = 'association';

    public function getLabel(): string
    {
        return match ($this) {
            self::SoleProprietorship => 'Sole proprietorship',
            self::GmbH => 'GmbH',
            self::AG => 'AG',
            self::Association => 'Association',
        };
    }

    /**
     * Only the sole proprietorship is supported by the tax engine for now.
     */
    public function isSupported(): bool
    {
        return $this === self::SoleProprietorship;
    }
}
