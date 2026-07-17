<?php

namespace App\Filament\Admin\Resources\Communes\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CommunesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('canton.code')
                    ->label('Canton')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('bfs_number')
                    ->label('BFS')
                    ->searchable(),
                TextColumn::make('tax_multiplier')
                    ->label('Multiplier %')
                    ->numeric(2)
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->date('d.m.Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('canton_id')
                    ->label('Canton')
                    ->relationship('canton', 'name_en'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
