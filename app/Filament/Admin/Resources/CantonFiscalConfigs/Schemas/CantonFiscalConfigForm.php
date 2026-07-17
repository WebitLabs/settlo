<?php

namespace App\Filament\Admin\Resources\CantonFiscalConfigs\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class CantonFiscalConfigForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /**
     * The canton is fixed for the life of a config lineage, so it is shown but
     * disabled; it is still dehydrated so a new version carries it forward.
     *
     * @return array<int, Component>
     */
    public static function components(): array
    {
        return [
            Select::make('canton_id')
                ->relationship('canton', 'name_en')
                ->disabled()
                ->dehydrated()
                ->required(),
            TextInput::make('year')
                ->integer()
                ->minValue(2000)
                ->maxValue(2100)
                ->required(),
            TextInput::make('cantonal_rate')
                ->label('Cantonal simple-tax rate (%)')
                ->numeric()
                ->step('0.0001')
                ->required(),
            TextInput::make('communal_multiplier_default')
                ->label('Default communal multiplier (%)')
                ->numeric()
                ->step('0.0001')
                ->required(),
            TextInput::make('church_rate')
                ->label('Church-tax rate (%)')
                ->numeric()
                ->step('0.0001')
                ->required(),
            TextInput::make('child_deduction')
                ->label('Child deduction (CHF)')
                ->integer()
                ->minValue(0)
                ->required(),
            DatePicker::make('effective_from')
                ->required(),
        ];
    }
}
