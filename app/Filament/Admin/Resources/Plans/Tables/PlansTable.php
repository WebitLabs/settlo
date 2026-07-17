<?php

namespace App\Filament\Admin\Resources\Plans\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('code')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('price_monthly')
                    ->label('Monthly')
                    ->money('chf')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('trial_days')
                    ->label('Trial')
                    ->suffix(' d')
                    ->alignEnd(),
                TextColumn::make('human_answers_quota')
                    ->label('Answers')
                    ->numeric()
                    ->alignEnd(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('sort_order');
    }
}
