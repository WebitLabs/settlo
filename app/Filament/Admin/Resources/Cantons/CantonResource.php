<?php

namespace App\Filament\Admin\Resources\Cantons;

use App\Filament\Admin\Resources\Cantons\Pages\ListCantons;
use App\Filament\Admin\Resources\Cantons\Tables\CantonsTable;
use App\Models\Canton;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Read-only reference list of the 26 Swiss cantons. Canton identity (code,
 * names, capital) is fixed reference data with no admin edit surface; fiscal
 * values live in the effective-dated cantonal fiscal configs.
 */
class CantonResource extends Resource
{
    protected static ?string $model = Canton::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Tax configuration';

    protected static ?string $navigationLabel = 'Cantons';

    protected static ?string $recordTitleAttribute = 'name_en';

    protected static ?int $navigationSort = 60;

    public static function table(Table $table): Table
    {
        return CantonsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCantons::route('/'),
        ];
    }
}
