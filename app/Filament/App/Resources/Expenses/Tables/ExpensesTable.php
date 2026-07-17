<?php

namespace App\Filament\App\Resources\Expenses\Tables;

use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Services\Expenses\ExpenseService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('expense_date')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('vendor')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('category.name_en')
                    ->label('Category')
                    ->badge()
                    ->color('gray')
                    ->placeholder('Uncategorised'),
                TextColumn::make('amount')
                    ->money('chf')
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('deductibility')
                    ->badge(),
                TextColumn::make('processing_status')
                    ->label('Processing')
                    ->badge()
                    ->icon(fn (ExpenseProcessingStatus $state): ?string => $state->isInFlight() ? 'heroicon-o-arrow-path' : null),
                TextColumn::make('status')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ExpenseStatus::class),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()->label('Review'),
                    self::confirmAction(),
                    self::viewReceiptAction(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            // Keep the "reading receipt…" state live while extraction runs.
            ->poll('10s')
            ->defaultSort('expense_date', 'desc');
    }

    private static function confirmAction(): Action
    {
        return Action::make('confirm')
            ->icon('heroicon-m-check-circle')
            ->color('success')
            ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::PendingReview)
            ->requiresConfirmation()
            ->modalHeading('Confirm expense')
            ->modalDescription('This includes the expense in your deductible total and tax estimate.')
            ->action(function (Expense $record): void {
                app(ExpenseService::class)->confirm($record);
                Notification::make()->title('Expense confirmed')->success()->send();
            });
    }

    private static function viewReceiptAction(): Action
    {
        return Action::make('receipt')
            ->label('View receipt')
            ->icon('heroicon-m-paper-clip')
            ->visible(fn (Expense $record): bool => filled($record->receipt_path))
            ->url(fn (Expense $record): string => route('receipts.show', $record))
            ->openUrlInNewTab();
    }
}
