<?php

namespace App\Filament\App\Resources\Invoices\Tables;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Invoicing\InvoicePdfService;
use App\Services\Invoicing\InvoiceService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use RuntimeException;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
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
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    EditAction::make()
                        ->visible(fn (Invoice $record): bool => $record->status->isEditable()),
                    self::sendAction(),
                    self::markPaidAction(),
                    self::downloadPdfAction(),
                    self::cancelAction(),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('invoice_number', 'desc');
    }

    private static function sendAction(): Action
    {
        return Action::make('send')
            ->icon('heroicon-m-paper-airplane')
            ->color('warning')
            ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Draft)
            ->requiresConfirmation()
            ->modalHeading('Send invoice')
            ->modalDescription('Sending freezes the amounts and generates the Swiss QR-bill. The invoice can no longer be edited.')
            ->action(function (Invoice $record): void {
                try {
                    app(InvoiceService::class)->send($record);
                    Notification::make()->title('Invoice sent')->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not send invoice')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private static function markPaidAction(): Action
    {
        return Action::make('markPaid')
            ->label('Mark paid')
            ->icon('heroicon-m-banknotes')
            ->color('success')
            ->visible(fn (Invoice $record): bool => in_array($record->status, [InvoiceStatus::Sent, InvoiceStatus::Overdue], true))
            ->schema([
                DatePicker::make('paid_at')
                    ->label('Payment date')
                    ->default(now())
                    ->required()
                    ->native(false),
                Select::make('method')
                    ->options([
                        'bank_transfer' => 'Bank transfer',
                        'cash' => 'Cash',
                        'card' => 'Card',
                        'other' => 'Other',
                    ])
                    ->default('bank_transfer')
                    ->required(),
            ])
            ->action(function (array $data, Invoice $record): void {
                try {
                    app(InvoiceService::class)->markPaid($record, Carbon::parse($data['paid_at']), $data['method']);
                    Notification::make()->title('Invoice marked as paid')->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not update invoice')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->visible(fn (Invoice $record): bool => ! in_array($record->status, [InvoiceStatus::Paid, InvoiceStatus::Cancelled], true))
            ->requiresConfirmation()
            ->modalHeading('Cancel invoice')
            ->action(function (Invoice $record): void {
                try {
                    app(InvoiceService::class)->cancel($record);
                    Notification::make()->title('Invoice cancelled')->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title('Could not cancel invoice')->body($e->getMessage())->danger()->send();
                }
            });
    }

    private static function downloadPdfAction(): Action
    {
        return Action::make('pdf')
            ->label('Download PDF')
            ->icon('heroicon-m-arrow-down-tray')
            ->visible(fn (Invoice $record): bool => $record->status !== InvoiceStatus::Draft)
            ->action(fn (Invoice $record) => app(InvoicePdfService::class)->download($record));
    }
}
