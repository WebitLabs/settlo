<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ExpenseStatus: string implements HasColor, HasIcon, HasLabel
{
    case PendingReview = 'pending_review';
    case Reviewed = 'reviewed';
    case Flagged = 'flagged';

    public function getLabel(): string
    {
        return match ($this) {
            self::PendingReview => 'Awaiting confirmation',
            self::Reviewed => 'Confirmed',
            self::Flagged => 'Flagged',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::PendingReview => 'warning',
            self::Reviewed => 'success',
            self::Flagged => 'danger',
        };
    }

    public function getIcon(): Heroicon
    {
        return match ($this) {
            self::PendingReview => Heroicon::OutlinedClock,
            self::Reviewed => Heroicon::OutlinedCheckCircle,
            self::Flagged => Heroicon::OutlinedFlag,
        };
    }

    /**
     * Only confirmed (reviewed) expenses feed the tax engine.
     */
    public function countsForTax(): bool
    {
        return $this === self::Reviewed;
    }
}
