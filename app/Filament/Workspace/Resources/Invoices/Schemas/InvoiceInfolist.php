<?php

namespace App\Filament\Workspace\Resources\Invoices\Schemas;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceService;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextEntry::make('draft_notice')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->color('gray')
                    ->state('Send the invoice to generate the Swiss QR-bill PDF.')
                    ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Draft),

                TextEntry::make('vat_not_registered_warning')
                    ->hiddenLabel()
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->color('warning')
                    ->state('This invoice charges VAT, but your business is not VAT-registered. Remove the VAT from the line items before sending it.')
                    ->visible(fn (Invoice $record): bool => bccomp((string) $record->vat_amount, '0', 2) > 0
                        && ! (bool) $record->businessEntity?->isVatRegistered()),

                Section::make('Invoice')
                    ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                    ->schema([
                        TextEntry::make('invoice_number')->label('Number')->weight('bold'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('language')->label('Language'),
                        TextEntry::make('issue_date')->date('d.m.Y'),
                        TextEntry::make('due_date')->date('d.m.Y'),
                        TextEntry::make('reference')->label('Your reference')->placeholder('—'),
                    ]),

                Section::make('Parties')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextEntry::make('businessEntity.name')
                            ->label('From')
                            ->state(fn (Invoice $record): string => $record->creditor_name ?: (string) $record->businessEntity?->name),
                        TextEntry::make('client.name')
                            ->label('Bill to')
                            ->state(fn (Invoice $record): string => (string) $record->client?->name),
                    ]),

                Section::make('Line items')
                    ->schema([
                        RepeatableEntry::make('lineItems')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Description'),
                                TableColumn::make('Qty')->width('6rem'),
                                TableColumn::make('Unit price')->width('9rem'),
                                TableColumn::make('VAT')->width('6rem'),
                                TableColumn::make('Amount')->width('9rem')->alignment(Alignment::End),
                            ])
                            ->schema([
                                TextEntry::make('description')->wrap(),
                                TextEntry::make('quantity')->numeric(decimalPlaces: 2),
                                TextEntry::make('unit_price')->formatStateUsing(self::money(...)),
                                TextEntry::make('vat_rate')->numeric(maxDecimalPlaces: 2)->suffix('%'),
                                TextEntry::make('line_total')->formatStateUsing(self::money(...))->alignEnd(),
                            ]),
                    ]),

                Section::make('Totals')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('vat_breakdown')
                            ->label('VAT breakdown')
                            ->state(function (Invoice $record): HtmlString {
                                $rows = app(InvoiceService::class)->vatBreakdown($record);

                                if ($rows === []) {
                                    return new HtmlString('—');
                                }

                                $lines = [];
                                foreach ($rows as $row) {
                                    $lines[] = e($row['rate'].'% on '.Money::format($row['base']).' → '.Money::format($row['vat']));
                                }

                                return new HtmlString(implode('<br>', $lines));
                            })
                            ->columnSpanFull(),
                        TextEntry::make('subtotal')->formatStateUsing(self::money(...)),
                        TextEntry::make('vat_amount')->label('VAT')->formatStateUsing(self::money(...)),
                        TextEntry::make('total')->formatStateUsing(self::money(...))->weight('bold'),
                        TextEntry::make('paid_amount')->formatStateUsing(self::money(...))
                            ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Paid),
                    ]),

                Grid::make(2)->schema([
                    Section::make('Timeline')
                        ->columnSpan(fn (Invoice $record): int|string => self::hasPayments($record) ? 1 : 'full')
                        ->schema([
                            TextEntry::make('created_at')->label('Created')->dateTime('d.m.Y H:i')->placeholder('—'),
                            TextEntry::make('sent_at')->label('Sent')->dateTime('d.m.Y H:i')->placeholder('—'),
                            TextEntry::make('paid_at')->label('Paid')->dateTime('d.m.Y H:i')->placeholder('—'),
                            TextEntry::make('status_changed_at')
                                ->label('Cancelled')
                                ->dateTime('d.m.Y H:i')
                                ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Cancelled),
                        ]),

                    Section::make('Payments')
                        ->visible(fn (Invoice $record): bool => self::hasPayments($record))
                        ->schema([
                            RepeatableEntry::make('payments')
                                ->hiddenLabel()
                                ->columns(3)
                                ->schema([
                                    TextEntry::make('paid_at')->label('Date')->date('d.m.Y'),
                                    TextEntry::make('amount')->formatStateUsing(self::money(...)),
                                    TextEntry::make('method')->badge(),
                                ]),
                        ]),
                ]),
            ]);
    }

    /**
     * Money in the app-wide "CHF 1'081.49" style.
     */
    private static function money(mixed $state): string
    {
        return Money::format($state);
    }

    private static function hasPayments(Invoice $record): bool
    {
        return $record->payments()->exists();
    }
}
