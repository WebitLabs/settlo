<?php

namespace App\Filament\Admin\Resources\Cantons\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CantonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('name_de')
                    ->label('German')
                    ->searchable(),
                TextColumn::make('name_en')
                    ->label('English')
                    ->searchable(),
                TextColumn::make('capital')
                    ->searchable(),
                TextColumn::make('communes_count')
                    ->label('Communes')
                    ->counts('communes')
                    ->alignEnd(),
            ])
            ->defaultSort('code');
    }
}
