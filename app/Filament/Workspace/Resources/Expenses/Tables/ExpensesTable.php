<?php

namespace App\Filament\Workspace\Resources\Expenses\Tables;

use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Filament\Workspace\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\Expenses\ExpenseService;
use App\Support\CurrentWorkspace;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Expense list. Confirming is the one step that makes an expense count for the
 * tax estimate and VAT summary, so it is an inline button (and a bulk action)
 * rather than hidden in the row menu.
 */
class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->description('Only confirmed expenses count towards your tax estimate and VAT summary.')
            // The category badge is shown on every row — load it in one query.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('category'))
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
                    ->formatStateUsing(fn (Expense $record): string => Money::format($record->amount, $record->currency_code))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('deductibility')
                    ->badge(),
                TextColumn::make('processing_status')
                    ->label('Processing')
                    ->badge()
                    ->state(fn (Expense $record): ?ExpenseProcessingStatus => $record->processing_status === ExpenseProcessingStatus::Manual
                        ? null
                        : $record->processing_status)
                    ->placeholder('—')
                    ->icon(fn (?ExpenseProcessingStatus $state): ?string => $state?->isInFlight() ? 'heroicon-o-arrow-path' : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ExpenseStatus::class),
                TrashedFilter::make(),
            ])
            ->persistFiltersInSession()
            ->recordActions([
                self::confirmAction()
                    ->button()
                    ->size(Size::Small),
                ActionGroup::make([
                    EditAction::make()->label('Review'),
                    self::viewReceiptAction(),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::confirmSelectedAction(),
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
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('update')
            ->visible(fn (Expense $record): bool => $record->status === ExpenseStatus::PendingReview)
            ->requiresConfirmation()
            ->modalHeading('Confirm expense')
            ->modalDescription('This includes the expense in your deductible total and tax estimate.')
            ->before(function (Action $action, Expense $record): void {
                if (ExpenseService::canBeConfirmed($record)) {
                    return;
                }

                Notification::make()
                    ->title('Add an amount and a category before confirming.')
                    ->danger()
                    ->actions([
                        Action::make('edit')
                            ->label('Edit expense')
                            ->button()
                            ->url(ExpenseResource::getUrl('edit', ['record' => $record])),
                    ])
                    ->send();

                $action->cancel();
            })
            ->action(function (Expense $record): void {
                app(ExpenseService::class)->confirm($record);
                Notification::make()->title('Expense confirmed')->success()->send();
            });
    }

    private static function confirmSelectedAction(): BulkAction
    {
        return BulkAction::make('confirmSelected')
            ->label('Confirm selected')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize(fn (): bool => CurrentWorkspace::entity()?->canWrite() ?? false)
            ->authorizeIndividualRecords('update')
            ->requiresConfirmation()
            ->modalDescription('Expenses without an amount or a category are skipped.')
            ->action(function (iterable $records): void {
                $service = app(ExpenseService::class);
                $confirmed = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if ($record->status !== ExpenseStatus::PendingReview) {
                        continue;
                    }

                    if (! ExpenseService::canBeConfirmed($record)) {
                        $skipped++;

                        continue;
                    }

                    $service->confirm($record);
                    $confirmed++;
                }

                Notification::make()
                    ->title(trans_choice(':count expense confirmed|:count expenses confirmed', $confirmed)
                        .($skipped > 0 ? ", {$skipped} skipped (missing amount or category)" : ''))
                    ->status($skipped > 0 ? 'warning' : 'success')
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
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
