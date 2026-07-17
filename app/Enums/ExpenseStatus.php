<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ExpenseStatus: string implements HasColor, HasLabel
{
    case PendingReview = 'pending_review';
    case Reviewed = 'reviewed';
    case Flagged = 'flagged';

    public function getLabel(): string
    {
        return match ($this) {
            self::PendingReview => 'Review needed',
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

    /**
     * Only confirmed (reviewed) expenses feed the tax engine.
     */
    public function countsForTax(): bool
    {
        return $this === self::Reviewed;
    }
}
