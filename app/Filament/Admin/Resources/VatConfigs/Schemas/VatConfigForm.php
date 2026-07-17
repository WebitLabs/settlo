<?php

namespace App\Filament\Admin\Resources\VatConfigs\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class VatConfigForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /**
     * Value fields shared by the direct-edit page and the new-version action.
     *
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
            TextInput::make('standard_rate')
                ->label('Standard rate (%)')
                ->numeric()
                ->step('0.001')
                ->required(),
            TextInput::make('reduced_rate')
                ->label('Reduced rate (%)')
                ->numeric()
                ->step('0.001')
                ->required(),
            TextInput::make('special_rate')
                ->label('Special rate (%)')
                ->numeric()
                ->step('0.001')
                ->required(),
            TextInput::make('registration_threshold')
                ->label('Registration threshold (CHF)')
                ->integer()
                ->minValue(0)
                ->required(),
            TextInput::make('registration_window_days')
                ->label('Registration window (days)')
                ->integer()
                ->minValue(0)
                ->required(),
            DatePicker::make('effective_from')
                ->required(),
        ];
    }
}
