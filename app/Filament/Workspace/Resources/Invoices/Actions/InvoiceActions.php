<?php

namespace App\Filament\Workspace\Resources\Invoices\Actions;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Invoicing\InvoicePdfService;
use App\Services\Invoicing\InvoiceService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Invoice lifecycle actions shared by the invoice table and the view page.
 * Visibility mirrors the status rules; the policy abilities (send, markPaid,
 * cancel) are the authoritative server-side check.
 */
final class InvoiceActions
{
    public static function send(): Action
    {
        return Action::make('send')
            ->label('Send')
            ->icon('heroicon-m-paper-airplane')
            ->color('warning')
            ->authorize('send')
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

    public static function markPaid(): Action
    {
        return Action::make('markPaid')
            ->label('Mark paid')
            ->icon('heroicon-m-banknotes')
            ->color('success')
            ->authorize('markPaid')
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

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label('Cancel invoice')
            ->icon('heroicon-m-x-circle')
            ->color('danger')
            ->authorize('cancel')
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

    /**
     * The document itself. A draft is previewed (watermarked, rendered from
     * the live business data) so the user can see what a client would get
     * before the irreversible send; an issued invoice downloads its frozen
     * document. Reading the PDF requires the same `view` ability as the record.
     */
    public static function pdf(): Action
    {
        return Action::make('pdf')
            ->label(fn (Invoice $record): string => $record->status === InvoiceStatus::Draft ? 'Preview PDF' : 'Download PDF')
            ->icon(fn (Invoice $record): string => $record->status === InvoiceStatus::Draft
                ? 'heroicon-m-eye'
                : 'heroicon-m-arrow-down-tray')
            ->authorize('view')
            ->action(fn (Invoice $record) => app(InvoicePdfService::class)->download($record));
    }
}
