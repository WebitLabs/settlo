<?php

namespace App\Filament\Firm\Resources\ClientEntities\RelationManagers;

use App\Enums\ExpenseStatus;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only window onto a client's expenses. An accountant may inspect but never
 * mutate: no header actions, no create/edit/delete, only a view modal.
 */
class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    protected static ?string $title = 'Expenses';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('vendor')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('expense_date')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->money('chf')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('deductibility')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ExpenseStatus::class),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('expense_date', 'desc');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    TextEntry::make('vendor'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('expense_date')->date('d.m.Y'),
                    TextEntry::make('deductibility')->badge(),
                    TextEntry::make('amount')->money('chf'),
                    TextEntry::make('vat_amount')->label('VAT')->money('chf'),
                    TextEntry::make('net_amount')->label('Net')->money('chf'),
                    TextEntry::make('deductible_pct')->label('Deductible')->suffix('%'),
                ]),
                TextEntry::make('description')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
