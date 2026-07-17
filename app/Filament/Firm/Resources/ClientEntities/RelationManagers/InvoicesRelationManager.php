<?php

namespace App\Filament\Firm\Resources\ClientEntities\RelationManagers;

use App\Enums\InvoiceStatus;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Read-only window onto a client's invoices. An accountant may inspect but never
 * mutate: no header actions, no create/edit/delete, only a view modal.
 */
class InvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    protected static ?string $title = 'Invoices';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('client.name')
                    ->label('Client')
                    ->searchable(),
                TextColumn::make('issue_date')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('total')
                    ->money('chf')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatus::class),
            ])
            ->headerActions([])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('issue_date', 'desc');
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(2)->schema([
                    TextEntry::make('invoice_number')->label('Number'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('client.name')->label('Client'),
                    TextEntry::make('issue_date')->date('d.m.Y'),
                    TextEntry::make('due_date')->date('d.m.Y'),
                    TextEntry::make('subtotal')->money('chf'),
                    TextEntry::make('vat_amount')->label('VAT')->money('chf'),
                    TextEntry::make('total')->money('chf'),
                ]),
                TextEntry::make('notes')
                    ->placeholder('—')
                    ->columnSpanFull(),
            ]);
    }
}
