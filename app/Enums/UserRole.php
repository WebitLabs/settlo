<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasColor, HasLabel
{
    case Owner = 'owner';
    case Accountant = 'accountant';
    case Superadmin = 'superadmin';

    public function getLabel(): string
    {
        return match ($this) {
            self::Owner => 'Business owner',
            self::Accountant => 'Accountant',
            self::Superadmin => 'Super admin',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Owner => 'primary',
            self::Accountant => 'info',
            self::Superadmin => 'danger',
        };
    }
}
