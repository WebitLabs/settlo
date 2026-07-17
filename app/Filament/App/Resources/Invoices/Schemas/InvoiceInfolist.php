<?php

namespace App\Filament\App\Resources\Invoices\Schemas;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('invoice_number')->label('Number')->weight('bold'),
                        TextEntry::make('status')->badge(),
                        TextEntry::make('language')->label('Language'),
                        TextEntry::make('issue_date')->date('d.m.Y'),
                        TextEntry::make('due_date')->date('d.m.Y'),
                        TextEntry::make('reference')->label('Your reference')->placeholder('—'),
                    ]),

                Section::make('Parties')
                    ->columns(2)
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
                            ->columns(12)
                            ->schema([
                                TextEntry::make('description')->hiddenLabel()->columnSpan(6),
                                TextEntry::make('quantity')->label('Qty')->numeric()->columnSpan(2),
                                TextEntry::make('unit_price')->label('Unit price')->money('CHF')->columnSpan(2),
                                TextEntry::make('vat_rate')->label('VAT')->suffix('%')->columnSpan(1),
                                TextEntry::make('line_total')->label('Amount')->money('CHF')->columnSpan(1),
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
                                    $lines[] = $row['rate'].'% on CHF '.number_format((float) $row['base'], 2, '.', "'")
                                        .' → CHF '.number_format((float) $row['vat'], 2, '.', "'");
                                }

                                return new HtmlString(implode('<br>', $lines));
                            })
                            ->columnSpanFull(),
                        TextEntry::make('subtotal')->money('CHF'),
                        TextEntry::make('vat_amount')->label('VAT')->money('CHF'),
                        TextEntry::make('total')->money('CHF')->weight('bold'),
                        TextEntry::make('paid_amount')->money('CHF')
                            ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::Paid),
                    ]),

                Grid::make(2)->schema([
                    Section::make('Timeline')
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
                        ->visible(fn (Invoice $record): bool => $record->payments()->exists())
                        ->schema([
                            RepeatableEntry::make('payments')
                                ->hiddenLabel()
                                ->columns(3)
                                ->schema([
                                    TextEntry::make('paid_at')->label('Date')->date('d.m.Y'),
                                    TextEntry::make('amount')->money('CHF'),
                                    TextEntry::make('method')->badge(),
                                ]),
                        ]),
                ]),
            ]);
    }
}
