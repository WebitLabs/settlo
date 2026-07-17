<?php

namespace App\Filament\Admin\Resources\Communes\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Communes carry no per-year uniqueness (the key is canton + BFS number), so
 * their tax multiplier is corrected in place rather than versioned. Only the
 * multiplier is editable; identity fields are shown but locked.
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
                ->required(),
        ]);
    }
}
