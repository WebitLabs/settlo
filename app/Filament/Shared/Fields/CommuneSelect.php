<?php

namespace App\Filament\Shared\Fields;

use App\Filament\Support\ValidatesOnBlur;
use App\Models\Commune;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;

/**
 * A searchable picker for the current communes of the canton selected in
 * $cantonField. Optionally required whenever that canton has communes, and
 * hinted when the commune's tax multiplier is only an estimate. Re-validated
 * whenever it changes, so a fixed error disappears at once.
 */
final class CommuneSelect
{
    public static function make(string $name = 'commune_id', string $cantonField = 'canton_id', bool $requiredWhenAvailable = false): Select
    {
        return Select::make($name)
            ->label('Commune')
            ->options(fn (Get $get): array => filled($get($cantonField))
                ? Commune::query()
                    ->where('canton_id', $get($cantonField))
                    ->whereNull('effective_to')
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all()
                : [])
            ->searchable()
            ->live()
            ->required(fn (Get $get): bool => $requiredWhenAvailable
                && filled($get($cantonField))
                && Commune::query()->where('canton_id', $get($cantonField))->whereNull('effective_to')->exists())
            ->hint(fn (?string $state): ?string => filled($state) && Commune::whereKey($state)->value('multiplier_is_estimated')
                ? 'Communal tax rate estimated from the canton average.'
                : null)
            ->tap(new ValidatesOnBlur);
    }
}
