<?php

namespace App\Filament\App\Resources\Invoices\Schemas;

use App\Enums\Language;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

class InvoiceForm
{
    /** Swiss VAT rates for 2026 (standard / reduced / accommodation / exempt). */
    private const VAT_RATES = [
        '8.1' => '8.1% (standard)',
        '2.6' => '2.6% (reduced)',
        '3.8' => '3.8% (accommodation)',
        '0' => '0% (exempt / export)',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Invoice details')
                    ->columns(2)
                    ->schema([
                        Select::make('client_id')
                            ->label('Client')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->options(fn (): array => Filament::getTenant()
                                ?->clients()
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all() ?? [])
                            // Defence in depth: the chosen client must belong to the
                            // active tenant, so a crafted client_id cannot reference
                            // another business's client.
                            ->rule(fn () => Rule::exists('clients', 'id')
                                ->where('business_entity_id', Filament::getTenant()?->getKey())),
                        Select::make('language')
                            ->options(Language::class)
                            ->default('en')
                            ->required(),
                        DatePicker::make('issue_date')
                            ->required()
                            ->default(now())
                            ->native(false),
                        DatePicker::make('due_date')
                            ->required()
                            ->default(now()->addDays(30))
                            ->native(false)
                            ->afterOrEqual('issue_date'),
                        TextInput::make('reference')
                            ->label('Your reference')
                            ->maxLength(255)
                            ->columnSpanFull(),
                    ]),

                Section::make('Line items')
                    ->schema([
                        Repeater::make('lineItems')
                            ->relationship()
                            ->hiddenLabel()
                            ->reorderable()
                            ->orderColumn('sort_order')
                            ->defaultItems(1)
                            ->addActionLabel('Add line')
                            ->columns(12)
                            ->schema([
                                TextInput::make('description')
                                    ->required()
                                    ->columnSpan(5),
                                TextInput::make('quantity')
                                    ->numeric()
                                    ->required()
                                    ->default(1)
                                    ->minValue(0)
                                    ->live(onBlur: true)
                                    ->columnSpan(2),
                                TextInput::make('unit_price')
                                    ->label('Unit price')
                                    ->numeric()
                                    ->required()
                                    ->default(0)
                                    ->prefix('CHF')
                                    ->live(onBlur: true)
                                    ->columnSpan(2),
                                Select::make('vat_rate')
                                    ->label('VAT')
                                    ->options(self::VAT_RATES)
                                    ->default('8.1')
                                    ->selectablePlaceholder(false)
                                    ->live()
                                    ->columnSpan(2),
                                Placeholder::make('line_total')
                                    ->label('Total')
                                    ->content(fn (Get $get): string => self::money(
                                        (float) $get('quantity') * (float) $get('unit_price')
                                    ))
                                    ->columnSpan(1),
                            ]),
                    ]),

                Section::make('Summary')
                    ->columns(3)
                    ->schema([
                        Placeholder::make('subtotal_display')
                            ->label('Subtotal')
                            ->content(fn (Get $get): string => self::money(self::totals($get)['subtotal'])),
                        Placeholder::make('vat_display')
                            ->label('VAT')
                            ->content(fn (Get $get): string => self::money(self::totals($get)['vat'])),
                        Placeholder::make('total_display')
                            ->label('Total')
                            ->content(fn (Get $get): string => self::money(self::totals($get)['total'])),
                        Placeholder::make('vat_breakdown_display')
                            ->label('VAT breakdown')
                            ->columnSpanFull()
                            ->content(function (Get $get): HtmlString {
                                $rows = self::vatRows($get);

                                if ($rows === []) {
                                    return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Add a line item to see the VAT breakdown.</span>');
                                }

                                $html = '';
                                foreach ($rows as $row) {
                                    $html .= '<div class="flex items-center justify-between text-sm">'
                                        .'<span class="text-gray-600 dark:text-gray-400">'.e($row['rate']).'% on '.e(self::money($row['base'])).'</span>'
                                        .'<span class="font-medium">'.e(self::money($row['vat'])).'</span>'
                                        .'</div>';
                                }

                                return new HtmlString($html);
                            }),
                    ]),

                Section::make('Notes')
                    ->columns(1)
                    ->collapsed()
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes (shown on the invoice)')
                            ->rows(2),
                        Textarea::make('internal_notes')
                            ->label('Internal notes (private)')
                            ->rows(2),
                    ]),
            ]);
    }

    /**
     * @return array{subtotal: float, vat: float, total: float}
     */
    private static function totals(Get $get): array
    {
        $subtotal = 0.0;
        $vat = 0.0;

        foreach ((array) $get('lineItems') as $line) {
            $net = (float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0);
            $subtotal += $net;
            $vat += $net * (float) ($line['vat_rate'] ?? 0) / 100;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'vat' => round($vat, 2),
            'total' => round($subtotal + $vat, 2),
        ];
    }

    /**
     * Group the live line-item state by VAT rate for the form-side breakdown.
     * Mirrors {@see InvoiceService::vatBreakdown()} but works on the unsaved
     * float state; the persisted BCMath figures remain authoritative.
     *
     * @return list<array{rate: string, base: float, vat: float}>
     */
    private static function vatRows(Get $get): array
    {
        $groups = [];

        foreach ((array) $get('lineItems') as $line) {
            $rate = (float) ($line['vat_rate'] ?? 0);
            $net = (float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0);
            $key = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
            $key = $key === '' ? '0' : $key;

            if (! isset($groups[$key])) {
                $groups[$key] = ['rate' => $key, 'base' => 0.0, 'vat' => 0.0];
            }

            $groups[$key]['base'] += $net;
            $groups[$key]['vat'] += $net * $rate / 100;
        }

        krsort($groups, SORT_NUMERIC);

        return array_values($groups);
    }

    private static function money(float $value): string
    {
        return 'CHF '.number_format($value, 2, '.', "'");
    }
}
