<?php

namespace App\Filament\Admin\Resources\CantonFiscalConfigs;

use App\Filament\Admin\Resources\CantonFiscalConfigs\Pages\EditCantonFiscalConfig;
use App\Filament\Admin\Resources\CantonFiscalConfigs\Pages\ListCantonFiscalConfigs;
use App\Filament\Admin\Resources\CantonFiscalConfigs\Schemas\CantonFiscalConfigForm;
use App\Filament\Admin\Resources\CantonFiscalConfigs\Tables\CantonFiscalConfigsTable;
use App\Filament\Admin\Resources\Concerns\VersionsEffectiveDatedConfig;
use App\Models\CantonFiscalConfig;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Effective-dated cantonal fiscal configuration (simple-tax rate, default
 * communal multiplier, church-tax rate, child deduction), one lineage per
 * canton and keyed by fiscal year. In-force rows are immutable; a change is a
 * new effective-dated version scoped to the same canton.
 */
class CantonFiscalConfigResource extends Resource
{
    use VersionsEffectiveDatedConfig;

    protected static ?string $model = CantonFiscalConfig::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Tax configuration';

    protected static ?string $navigationLabel = 'Cantonal fiscal configs';

    protected static ?string $modelLabel = 'cantonal fiscal config';

    protected static ?int $navigationSort = 62;

    public static function form(Schema $schema): Schema
    {
        return CantonFiscalConfigForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CantonFiscalConfigsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('canton');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCantonFiscalConfigs::route('/'),
            'edit' => EditCantonFiscalConfig::route('/{record}/edit'),
        ];
    }
}
