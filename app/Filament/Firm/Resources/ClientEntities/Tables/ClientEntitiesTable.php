<?php

namespace App\Filament\Firm\Resources\ClientEntities\Tables;

use App\Models\BusinessEntity;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClientEntitiesTable
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
                    ->state(fn (BusinessEntity $record): ?string => $record->owner?->getFilamentName()),
                TextColumn::make('canton.code')
                    ->label('Canton')
                    ->badge()
                    ->sortable(),
                TextColumn::make('revenue_ytd')
                    ->label('Revenue YTD')
                    ->money('chf')
                    ->state(fn (BusinessEntity $record): float => (float) ($record->revenue_ytd ?? 0))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('vat_status')
                    ->label('VAT')
                    ->badge()
                    ->state(fn (BusinessEntity $record): string => filled($record->mwst_number) ? 'Registered' : '—')
                    ->color(fn (string $state): string => $state === 'Registered' ? 'success' : 'gray'),
                TextColumn::make('assigned_accountant')
                    ->label('Assigned accountant')
                    ->state(fn (BusinessEntity $record): ?string => $record->accountantAssignments
                        ->first()?->accountant?->getFilamentName()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('name');
    }
}
