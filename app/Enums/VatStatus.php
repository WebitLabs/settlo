<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum VatStatus: string implements HasColor, HasLabel
{
    case NotRegistered = 'not_registered';
    case RegisteredVoluntary = 'registered_voluntary';
    case RegisteredMandatory = 'registered_mandatory';
    case Exempt = 'exempt';

    public function getLabel(): string
    {
        return match ($this) {
            self::NotRegistered => 'Not registered (under CHF 100k)',
            self::RegisteredVoluntary => 'Registered (voluntary)',
            self::RegisteredMandatory => 'Registered (mandatory)',
            self::Exempt => 'Exempt',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::NotRegistered => 'gray',
            self::RegisteredVoluntary, self::RegisteredMandatory => 'success',
            self::Exempt => 'info',
        };
    }

    public function isRegistered(): bool
    {
        return $this === self::RegisteredVoluntary || $this === self::RegisteredMandatory;
    }
}
