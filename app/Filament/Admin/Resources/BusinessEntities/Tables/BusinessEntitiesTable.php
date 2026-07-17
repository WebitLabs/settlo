<?php

namespace App\Filament\Admin\Resources\BusinessEntities\Tables;

use App\Models\BusinessEntity;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BusinessEntitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Business')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('owner_name')
                    ->label('Owner')
                    ->state(fn (BusinessEntity $record): ?string => $record->owner?->getFilamentName())
                    ->placeholder('—'),
                TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                TextColumn::make('canton.code')
                    ->label('Canton')
                    ->badge()
                    ->sortable(),
                TextColumn::make('invoices_count')
                    ->label('Invoices')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('expenses_count')
                    ->label('Expenses')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('d.m.Y')
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
