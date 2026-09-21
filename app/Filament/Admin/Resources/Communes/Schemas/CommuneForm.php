<?php

namespace App\Filament\Admin\Resources\Communes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Communes carry no per-year uniqueness (the key is canton + BFS number), so
 * their tax multiplier is corrected in place rather than versioned. Only the
 * multiplier (and whether it is estimated) is editable; identity fields are
 * shown but locked. Entering a multiplier marks it as real (not estimated), so
 * a later commune import keeps it.
 */
class CommuneForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('canton_id')
                ->relationship('canton', 'name_en')
                ->disabled(),
            TextInput::make('name')
                ->disabled(),
            TextInput::make('bfs_number')
                ->label('BFS number')
                ->disabled(),
            TextInput::make('tax_multiplier')
                ->label('Tax multiplier (%)')
                ->numeric()
                ->step('0.0001')
                ->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set): mixed => $set('multiplier_is_estimated', false)),
            Toggle::make('multiplier_is_estimated')
                ->label('Multiplier is estimated')
                ->helperText('Turn off once the real Steuerfuss has been entered.'),
        ]);
    }
}
