<?php

namespace App\Filament\Workspace\Resources\Invoices\Schemas;

use App\Enums\Language;
use App\Filament\Support\ValidatesOnBlur;
use App\Models\BusinessEntity;
use App\Models\Client;
use App\Services\Invoicing\InvoiceTotals;
use App\Services\Tax\RateRepository;
use App\Support\Money;
use Carbon\Exceptions\InvalidFormatException;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rule;

/**
 * Invoice create/edit form. Sections are stacked full width; line items use a
 * table repeater, and every computed figure (line totals, summary, VAT
 * breakdown) comes from {@see InvoiceTotals} — the same BCMath code that
 * computes the persisted totals, so the preview always matches what is saved.
 */
class InvoiceForm
{
    /** Select the whole value on focus, so typing replaces it instead of appending. */
    private const SELECT_ON_FOCUS = ['x-on:focus' => '$event.target.select()'];

    /** Used only when neither the client nor the business stores a payment term. */
    private const int FALLBACK_PAYMENT_TERM_DAYS = 30;

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Invoice details')
                    ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
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
                                ->where('business_entity_id', Filament::getTenant()?->getKey()))
                            // Choosing a client applies that client's own payment
                            // term and the language its invoices are printed in.
                            ->afterStateUpdated(function (Get $get, Set $set): void {
                                $set('due_date', self::dueDateFor($get('client_id'), $get('issue_date')));
                                $set('language', self::languageFor($get('client_id')));
                            })
                            ->tap(new ValidatesOnBlur),
                        Select::make('language')
                            ->label('Invoice language')
                            ->helperText("The client's stored language, unless you change it here.")
                            ->options(Language::class)
                            ->default(fn (): string => self::languageFor(null))
                            ->selectablePlaceholder(false)
                            ->required(),
                        DatePicker::make('issue_date')
                            ->required()
                            ->default(now())
                            ->native(false)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set): mixed => $set(
                                'due_date',
                                self::dueDateFor($get('client_id'), $get('issue_date')),
                            )),
                        DatePicker::make('due_date')
                            ->required()
                            ->default(fn (): string => self::dueDateFor(null, null))
                            ->native(false)
                            ->helperText(fn (Get $get): string => self::paymentTermHint($get('client_id')))
                            ->afterOrEqual('issue_date'),
                        TextInput::make('reference')
                            ->label('Your reference')
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->tap(new ValidatesOnBlur),
                    ]),

                Section::make('Line items')
                    ->schema([
                        TextEntry::make('not_vat_registered_notice')
                            ->hiddenLabel()
                            ->icon(Heroicon::OutlinedInformationCircle)
                            ->color('gray')
                            ->state('Your business is not VAT-registered, so this invoice is issued without VAT.')
                            ->visible(fn (): bool => ! self::tenantIsVatRegistered()),
                        Repeater::make('lineItems')
                            ->relationship()
                            ->hiddenLabel()
                            ->table(fn (): array => array_values(array_filter([
                                TableColumn::make('Description')->markAsRequired(),
                                TableColumn::make('Qty')->width('7rem')->markAsRequired(),
                                TableColumn::make('Unit price (CHF)')->width('10rem')->markAsRequired(),
                                self::tenantIsVatRegistered() ? TableColumn::make('VAT')->width('10rem') : null,
                                TableColumn::make('Total')->width('9rem')->alignment(Alignment::End),
                            ])))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::withoutVatUnlessRegistered($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::withoutVatUnlessRegistered($data))
                            ->reorderable()
                            ->orderColumn('sort_order')
                            ->defaultItems(1)
                            ->addActionLabel('Add line')
                            ->schema(fn (): array => [
                                TextInput::make('description')
                                    ->required()
                                    ->maxLength(255)
                                    ->tap(new ValidatesOnBlur),
                                TextInput::make('quantity')
                                    ->required()
                                    ->numeric()
                                    ->rules(['gt:0'])
                                    ->default(1)
                                    ->step('any')
                                    ->inputMode('decimal')
                                    ->extraInputAttributes(self::SELECT_ON_FOCUS, merge: true)
                                    ->tap(new ValidatesOnBlur(debounceMs: 400)),
                                TextInput::make('unit_price')
                                    ->label('Unit price')
                                    ->required()
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('0.01')
                                    ->placeholder('0.00')
                                    ->inputMode('decimal')
                                    ->extraInputAttributes(self::SELECT_ON_FOCUS, merge: true)
                                    ->tap(new ValidatesOnBlur(debounceMs: 400)),
                                self::vatRateField(),
                                TextEntry::make('line_total_display')
                                    ->hiddenLabel()
                                    ->alignEnd()
                                    ->state(fn (Get $get): string => self::money(
                                        InvoiceTotals::lineNet($get('quantity'), $get('unit_price'))
                                    )),
                            ]),
                    ]),

                Section::make('Summary')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextEntry::make('subtotal_display')
                            ->label('Subtotal')
                            ->state(fn (Get $get): string => self::money(self::totals($get)['subtotal'])),
                        TextEntry::make('vat_display')
                            ->label('VAT')
                            ->visible(fn (): bool => self::tenantIsVatRegistered())
                            ->state(fn (Get $get): string => self::money(self::totals($get)['vat'])),
                        TextEntry::make('total_display')
                            ->label('Total')
                            ->weight('bold')
                            ->state(fn (Get $get): string => self::money(self::totals($get)['total'])),
                        TextEntry::make('vat_breakdown_display')
                            ->label('VAT breakdown')
                            ->visible(fn (): bool => self::tenantIsVatRegistered())
                            ->columnSpanFull()
                            ->html()
                            ->state(fn (Get $get): HtmlString => self::vatBreakdownHtml($get)),
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
     * The per-line VAT select, or a hidden zero rate when the business is not
     * VAT-registered (a hidden field keeps the table repeater's columns aligned).
     */
    private static function vatRateField(): Select|Hidden
    {
        if (! self::tenantIsVatRegistered()) {
            return Hidden::make('vat_rate')->default('0');
        }

        return Select::make('vat_rate')
            ->label('VAT')
            ->options(fn (): array => app(RateRepository::class)->vatRateOptions(self::fiscalYear()))
            ->default(fn (): string => app(RateRepository::class)->standardVatRate(self::fiscalYear()))
            ->selectablePlaceholder(false)
            ->live();
    }

    /**
     * The fiscal year whose VAT rates apply to the form.
     */
    private static function fiscalYear(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }

    /**
     * Whether the current tenant may charge VAT on its invoices.
     */
    public static function tenantIsVatRegistered(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof BusinessEntity && $tenant->isVatRegistered();
    }

    /**
     * Server-side guard: a business that is not VAT-registered never stores a
     * VAT rate on its line items, whatever the submitted payload says.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function withoutVatUnlessRegistered(array $data): array
    {
        return self::tenantIsVatRegistered() ? $data : [...$data, 'vat_rate' => 0];
    }

    /**
     * @return array{subtotal: string, vat: string, total: string, breakdown: array<string, array{rate: string, base: string, vat: string}>}
     */
    private static function totals(Get $get): array
    {
        return InvoiceTotals::forLines((array) $get('lineItems'));
    }

    private static function vatBreakdownHtml(Get $get): HtmlString
    {
        $rows = self::totals($get)['breakdown'];

        if ($rows === []) {
            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">Add a line item to see the VAT breakdown.</span>');
        }

        $html = '';
        foreach ($rows as $row) {
            $html .= '<div class="flex items-center justify-between gap-4 text-sm">'
                .'<span class="text-gray-600 dark:text-gray-400">'.e($row['rate']).'% on '.e(self::money($row['base'])).'</span>'
                .'<span class="font-medium">'.e(self::money($row['vat'])).'</span>'
                .'</div>';
        }

        return new HtmlString($html);
    }

    public static function money(string $value): string
    {
        return Money::format($value);
    }

    /**
     * The due date implied by the stored payment term: the client's own term
     * when it has one, otherwise the business default (and 30 days only when
     * neither is set).
     */
    public static function dueDateFor(mixed $clientId, mixed $issueDate): string
    {
        // The issue date comes from Livewire state, so anything can arrive
        // here; an unparseable value simply falls back to today.
        try {
            $issuedOn = filled($issueDate) ? Carbon::parse((string) $issueDate) : Carbon::today();
        } catch (InvalidFormatException) {
            $issuedOn = Carbon::today();
        }

        return $issuedOn->addDays(self::paymentTermDays($clientId))->toDateString();
    }

    /**
     * The language an invoice for this client is printed in: the client's own
     * stored language, otherwise the business default, otherwise English.
     */
    public static function languageFor(mixed $clientId): string
    {
        $tenant = Filament::getTenant();

        $clientLanguage = filled($clientId)
            ? Client::query()
                ->whereKey($clientId)
                ->where('business_entity_id', $tenant?->getKey())
                ->value('default_language')
            : null;

        $language = $clientLanguage ?: $tenant?->default_language;

        return Language::tryFrom((string) $language)?->value ?? Language::English->value;
    }

    /**
     * The payment term in days that applies to an invoice for this client.
     */
    public static function paymentTermDays(mixed $clientId): int
    {
        $tenant = Filament::getTenant();

        $clientTerm = filled($clientId)
            ? Client::query()
                ->whereKey($clientId)
                ->where('business_entity_id', $tenant?->getKey())
                ->value('default_payment_term_days')
            : null;

        return (int) ($clientTerm ?? $tenant?->default_payment_term_days ?? self::FALLBACK_PAYMENT_TERM_DAYS);
    }

    private static function paymentTermHint(mixed $clientId): string
    {
        $days = self::paymentTermDays($clientId);

        return $days === 1
            ? 'From the stored payment term (1 day).'
            : "From the stored payment term ({$days} days).";
    }
}
