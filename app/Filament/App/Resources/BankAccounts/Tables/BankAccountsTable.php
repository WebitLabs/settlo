<?php

namespace App\Filament\App\Resources\BankAccounts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BankAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('account_name')
                    ->label('Label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bank_name')
                    ->label('Bank')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('iban')
                    ->label('IBAN')
                    ->searchable(),
                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('is_default', 'desc');
    }
}
