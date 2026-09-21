<?php

namespace App\Filament\Shared\Fields;

use App\Filament\Support\ValidatesOnBlur;
use App\Models\Canton;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use Livewire\Component as LivewireComponent;

/**
 * A searchable canton picker labelled "ZH — Zurich". Changing the canton
 * clears the commune field (and its stale error, when one is given) and
 * re-validates the canton, so a fixed "required" error disappears at once.
 */
final class CantonSelect
{
    public static function make(string $name = 'canton_id', ?string $communeField = 'commune_id'): Select
    {
        $select = Select::make($name)
            ->label('Canton')
            ->options(fn (): array => self::options())
            ->searchable()
            ->live();

        if ($communeField !== null) {
            $select->afterStateUpdated(function (Set $set, LivewireComponent $livewire, Select $component) use ($name, $communeField): void {
                $set($communeField, null);
                $livewire->resetValidation(self::siblingStatePath($component->getStatePath(), $name, $communeField));
            });
        }

        return $select->tap(new ValidatesOnBlur);
    }

    /**
     * Canton options labelled "ZH — Zurich", ordered by name.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return Canton::orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (Canton $canton): array => [
                $canton->getKey() => "{$canton->code} — {$canton->name_en}",
            ])
            ->all();
    }

    /**
     * The state path of a field that sits next to the canton field.
     */
    private static function siblingStatePath(string $cantonStatePath, string $cantonName, string $siblingName): string
    {
        return Str::endsWith($cantonStatePath, $cantonName)
            ? Str::replaceLast($cantonName, $siblingName, $cantonStatePath)
            : $siblingName;
    }
}
