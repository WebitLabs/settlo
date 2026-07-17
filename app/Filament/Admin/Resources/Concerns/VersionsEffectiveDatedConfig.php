<?php

namespace App\Filament\Admin\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Shared authorization rules for effective-dated tax-configuration resources.
 * New rows are only ever created by superseding an in-force row (the "New
 * version" action), never from a blank form, and direct field edits are limited
 * to rows whose period has not started yet or a same-day correction. In-force
 * and historical rows are immutable.
 */
trait VersionsEffectiveDatedConfig
{
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record->effective_from->startOfDay()->greaterThanOrEqualTo(now()->startOfDay());
    }
}
