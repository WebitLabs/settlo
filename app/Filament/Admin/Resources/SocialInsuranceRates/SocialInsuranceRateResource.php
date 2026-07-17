<?php

namespace App\Filament\Admin\Resources\SocialInsuranceRates;

use App\Filament\Admin\Resources\Concerns\VersionsEffectiveDatedConfig;
use App\Filament\Admin\Resources\SocialInsuranceRates\Pages\EditSocialInsuranceRate;
use App\Filament\Admin\Resources\SocialInsuranceRates\Pages\ListSocialInsuranceRates;
use App\Filament\Admin\Resources\SocialInsuranceRates\Schemas\SocialInsuranceRateForm;
use App\Filament\Admin\Resources\SocialInsuranceRates\Tables\SocialInsuranceRatesTable;
use App\Models\SocialInsuranceRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Effective-dated self-employed social-insurance rates (AHV/IV/EO) and Pillar 3a
 * limits, keyed by fiscal year. Rows in force are immutable: a rate change is a
 * new effective-dated version that end-dates the open row, never an edit of a
 * past or current period.
 */
class SocialInsuranceRateResource extends Resource
{
    use VersionsEffectiveDatedConfig;

    protected static ?string $model = SocialInsuranceRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Tax configuration';

    protected static ?string $navigationLabel = 'Social insurance rates';

    protected static ?string $modelLabel = 'Social insurance rate';

    protected static ?string $recordTitleAttribute = 'year';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return SocialInsuranceRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SocialInsuranceRatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSocialInsuranceRates::route('/'),
            'edit' => EditSocialInsuranceRate::route('/{record}/edit'),
        ];
    }
}
