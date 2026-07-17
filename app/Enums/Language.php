<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Language: string implements HasLabel
{
    case English = 'en';
    case German = 'de';
    case French = 'fr';
    case Italian = 'it';

    public function getLabel(): string
    {
        return match ($this) {
            self::English => 'English',
            self::German => 'Deutsch',
            self::French => 'Français',
            self::Italian => 'Italiano',
        };
    }
}
