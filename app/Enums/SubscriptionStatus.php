<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SubscriptionStatus: string implements HasColor, HasLabel
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case Incomplete = 'incomplete';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
            self::Incomplete => 'Payment required',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Trialing => 'info',
            self::Active => 'success',
            self::PastDue => 'warning',
            self::Cancelled, self::Expired, self::Incomplete => 'danger',
        };
    }

    /**
     * Whether the subscription grants write access to the workspace. Expired and
     * cancelled subscriptions drop it into a read-only locked state; an
     * incomplete one (checkout not finished) grants nothing.
     */
    public function grantsAccess(): bool
    {
        return $this === self::Trialing || $this === self::Active || $this === self::PastDue;
    }
}
