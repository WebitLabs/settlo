<?php

namespace App\Filament\Admin\Resources\VatConfigs;

use App\Filament\Admin\Resources\Concerns\VersionsEffectiveDatedConfig;
use App\Filament\Admin\Resources\VatConfigs\Pages\EditVatConfig;
use App\Filament\Admin\Resources\VatConfigs\Pages\ListVatConfigs;
use App\Filament\Admin\Resources\VatConfigs\Schemas\VatConfigForm;
use App\Filament\Admin\Resources\VatConfigs\Tables\VatConfigsTable;
use App\Models\VatConfig;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Effective-dated Swiss VAT configuration: standard/reduced/special rates and
 * the registration threshold, keyed by fiscal year. Rates in force are never
 * mutated — a rate change is applied by superseding the open row with a new
 * version (see the "New version" action), and only future or same-day rows may
 * be edited directly.
 */
class VatConfigResource extends Resource
{
    use VersionsEffectiveDatedConfig;

    protected static ?string $model = VatConfig::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Tax configuration';

    protected static ?string $navigationLabel = 'VAT configuration';

    protected static ?string $modelLabel = 'VAT configuration';

    protected static ?string $pluralModelLabel = 'VAT configurations';

    protected static ?string $recordTitleAttribute = 'year';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return VatConfigForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VatConfigsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVatConfigs::route('/'),
            'edit' => EditVatConfig::route('/{record}/edit'),
        ];
    }
}
