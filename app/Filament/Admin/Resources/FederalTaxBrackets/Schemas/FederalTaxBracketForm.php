<?php

namespace App\Filament\Admin\Resources\FederalTaxBrackets\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class FederalTaxBracketForm
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
            Select::make('tariff')
                ->options([
                    'A' => 'A — single',
                    'B' => 'B — married / single parent',
                ])
                ->required(),
            TextInput::make('bracket_from')
                ->label('Bracket from (CHF)')
                ->integer()
                ->minValue(0)
                ->required(),
            TextInput::make('bracket_to')
                ->label('Bracket to (CHF)')
                ->helperText('Leave empty for the top bracket.')
                ->integer()
                ->minValue(0),
            TextInput::make('rate')
                ->label('Rate on excess (%)')
                ->numeric()
                ->step('0.001')
                ->required(),
            TextInput::make('base_amount')
                ->label('Base tax at bracket floor (CHF)')
                ->numeric()
                ->step('0.01')
                ->required(),
            DatePicker::make('effective_from')
                ->required(),
        ];
    }
}
