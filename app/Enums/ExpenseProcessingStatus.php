<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The lifecycle of a receipt upload through the extraction pipeline. Distinct
 * from ExpenseStatus, which tracks the human review/confirmation state.
 */
enum ExpenseProcessingStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Extracted = 'extracted';
    case Failed = 'failed';
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Queued',
            self::Processing => 'Reading receipt…',
            self::Extracted => 'Data extracted',
            self::Failed => 'Extraction failed',
            self::Manual => 'Entered manually',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending, self::Processing => 'warning',
            self::Extracted, self::Manual => 'success',
            self::Failed => 'danger',
        };
    }

    public function isInFlight(): bool
    {
        return $this === self::Pending || $this === self::Processing;
    }
}
