<?php

namespace App\Filament\Admin\Resources\Communes;

use App\Filament\Admin\Resources\Communes\Pages\EditCommune;
use App\Filament\Admin\Resources\Communes\Pages\ListCommunes;
use App\Filament\Admin\Resources\Communes\Schemas\CommuneForm;
use App\Filament\Admin\Resources\Communes\Tables\CommunesTable;
use App\Models\Commune;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Commune tax multipliers. Unlike the year-keyed configs, a commune has a single
 * row per canton + BFS number, so its multiplier is corrected in place (audited)
 * rather than superseded with a new effective-dated version. Communes are not
 * created from the admin panel — they are seeded reference data.
 */
class CommuneResource extends Resource
{
    protected static ?string $model = Commune::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Tax configuration';

    protected static ?string $navigationLabel = 'Communes';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 61;

    public static function form(Schema $schema): Schema
    {
        return CommuneForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CommunesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('canton');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommunes::route('/'),
            'edit' => EditCommune::route('/{record}/edit'),
        ];
    }
}
