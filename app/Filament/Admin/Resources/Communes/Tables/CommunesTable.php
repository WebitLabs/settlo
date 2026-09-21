<?php

namespace App\Filament\Admin\Resources\Communes\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
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
                ToggleColumn::make('multiplier_is_estimated')
                    ->label('Estimated'),
                TextColumn::make('effective_from')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('effective_to')
                    ->label('Retired')
                    ->date('d.m.Y')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('canton_id')
                    ->label('Canton')
                    ->relationship('canton', 'name_en'),
                TernaryFilter::make('multiplier_is_estimated')
                    ->label('Multiplier')
                    ->placeholder('All communes')
                    ->trueLabel('Estimated from the canton default')
                    ->falseLabel('Real Steuerfuss'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }
}
