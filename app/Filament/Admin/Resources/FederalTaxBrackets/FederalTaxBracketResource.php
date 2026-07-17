<?php

namespace App\Filament\Admin\Resources\FederalTaxBrackets;

use App\Filament\Admin\Resources\Concerns\VersionsEffectiveDatedConfig;
use App\Filament\Admin\Resources\FederalTaxBrackets\Pages\EditFederalTaxBracket;
use App\Filament\Admin\Resources\FederalTaxBrackets\Pages\ListFederalTaxBrackets;
use App\Filament\Admin\Resources\FederalTaxBrackets\Schemas\FederalTaxBracketForm;
use App\Filament\Admin\Resources\FederalTaxBrackets\Tables\FederalTaxBracketsTable;
use App\Models\FederalTaxBracket;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Effective-dated federal direct-tax brackets (tariff A single, tariff B married
 * / single parent). The table has no per-year uniqueness, so each bracket row is
 * versioned independently: superseding a row end-dates it and inserts an
 * identical-key row for the new period, leaving past periods untouched.
 * Wholesale re-tariffing (cloning a full tariff into a new year) is out of scope
 * for this admin surface and is handled by a seeder.
 */
class FederalTaxBracketResource extends Resource
{
    use VersionsEffectiveDatedConfig;

    protected static ?string $model = FederalTaxBracket::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static string|UnitEnum|null $navigationGroup = 'Tax configuration';

    protected static ?string $navigationLabel = 'Federal tax brackets';

    protected static ?string $modelLabel = 'federal tax bracket';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return FederalTaxBracketForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FederalTaxBracketsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFederalTaxBrackets::route('/'),
            'edit' => EditFederalTaxBracket::route('/{record}/edit'),
        ];
    }
}
