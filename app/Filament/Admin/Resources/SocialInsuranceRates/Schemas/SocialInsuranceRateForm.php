<?php

namespace App\Filament\Admin\Resources\SocialInsuranceRates\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class SocialInsuranceRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /**
     * @return array<int, Component>
     */
    public static function components(): array
    {
        return [
            TextInput::make('year')
                ->integer()
                ->minValue(2000)
                ->maxValue(2100)
                ->required(),
            TextInput::make('ahv_rate')
                ->label('AHV rate (%)')
                ->numeric()
                ->step('0.001')
                ->required(),
            TextInput::make('iv_rate')
                ->label('IV rate (%)')
                ->numeric()
                ->step('0.001')
                ->required(),
            TextInput::make('eo_rate')
                ->label('EO rate (%)')
                ->numeric()
                ->step('0.001')
                ->required(),
            TextInput::make('pillar3a_max_se')
                ->label('Pillar 3a max, no pillar 2 (CHF)')
                ->integer()
                ->minValue(0)
                ->required(),
            TextInput::make('pillar3a_max_with_p2')
                ->label('Pillar 3a max, with pillar 2 (CHF)')
                ->integer()
                ->minValue(0)
                ->required(),
            TextInput::make('ahv_minimum')
                ->label('AHV minimum (CHF/year)')
                ->integer()
                ->minValue(0)
                ->required(),
            TextInput::make('age_exemption_amount')
                ->label('Age exemption amount (CHF)')
                ->integer()
                ->minValue(0)
                ->required(),
            DatePicker::make('effective_from')
                ->required(),
        ];
    }
}
