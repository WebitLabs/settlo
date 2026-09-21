<?php

namespace App\Filament\Workspace\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Filament\Workspace\Resources\Invoices\Actions\InvoiceActions;
use App\Models\Invoice;
use App\Support\Money;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The client name is shown on every row — load it in one query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('client'))
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Number')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('client.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('issue_date')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->date('d.m.Y')
                    ->sortable()
                    ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('total')
                    ->formatStateUsing(fn (Invoice $record): string => Money::format($record->total, $record->currency_code))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(InvoiceStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make()
                        ->visible(fn (Invoice $record): bool => $record->status->isEditable()),
                    InvoiceActions::send(),
                    InvoiceActions::markPaid(),
                    InvoiceActions::pdf(),
                    InvoiceActions::cancel(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('invoice_number', 'desc');
    }
}
