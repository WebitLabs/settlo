<?php

namespace App\Filament\Workspace\Resources\Expenses\Schemas;

use App\Enums\DeductibilityStatus;
use App\Filament\Support\ValidatesOnBlur;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Expenses\ExpenseService;
use App\Services\Tax\RateRepository;
use App\Support\CurrentWorkspace;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Expense create/edit form. A manual entry needs a vendor, a positive amount
 * and a category; with an uploaded receipt those may stay empty because the
 * extraction pipeline fills them in. The VAT amount is derived from the gross
 * amount and rate (Swiss receipts show VAT-inclusive totals).
 */
class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Receipt')
                    ->schema([
                        FileUpload::make('receipt_path')
                            ->label('Receipt')
                            ->disk('receipts')
                            ->visibility('private')
                            // Per-tenant prefix: the download controller asserts
                            // it, so one workspace's receipt path can never
                            // resolve inside another's.
                            ->directory(fn (): string => 'receipts/'.(Filament::getTenant()?->getKey() ?? 'shared'))
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(20480)
                            ->downloadable()
                            ->live()
                            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Upload a photo or PDF and Settlo will read the details for you.')
                            ->columnSpanFull(),
                        TextEntry::make('ai_suggestion')
                            ->label('Settlo read')
                            ->visible(fn (?Expense $record): bool => $record?->ai_suggested_category_id !== null)
                            ->state(function (?Expense $record): string {
                                if (! $record?->ai_suggested_category_id) {
                                    return '—';
                                }
                                $category = ExpenseCategory::find($record->ai_suggested_category_id);
                                $confidence = $record->ai_confidence !== null
                                    ? ' · '.round((float) $record->ai_confidence * 100).'% confidence'
                                    : '';

                                return ($category?->name_en ?? 'Uncategorised').$confidence;
                            }),
                    ]),

                Section::make('Details')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('vendor')
                            ->required(fn (Get $get): bool => self::isManualEntry($get))
                            ->maxLength(255)
                            ->tap(new ValidatesOnBlur),
                        DatePicker::make('expense_date')
                            ->required()
                            ->default(now())
                            ->native(false),
                        TextInput::make('amount')
                            ->label('Total amount (incl. VAT)')
                            ->numeric()
                            ->prefix('CHF')
                            ->required(fn (Get $get): bool => self::isManualEntry($get))
                            ->rules(fn (Get $get): array => self::isManualEntry($get)
                                ? ['numeric', 'gt:0']
                                : ['nullable', 'numeric', 'min:0'])
                            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Leave empty when you upload a receipt — Settlo reads it for you.')
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::fillVatAmount($get, $set))
                            ->tap(new ValidatesOnBlur(debounceMs: 500)),
                        TextInput::make('vat_rate')
                            ->label('VAT rate (%)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->datalist(fn (): array => array_keys(app(RateRepository::class)->vatRateOptions(self::fiscalYear())))
                            ->default(fn (): string => self::defaultVatRate())
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::fillVatAmount($get, $set))
                            ->tap(new ValidatesOnBlur(debounceMs: 500)),
                        TextInput::make('vat_amount')
                            ->label('VAT amount')
                            ->numeric()
                            ->prefix('CHF')
                            ->minValue(0)
                            ->rule(
                                fn (Field $component): string => 'lte:'.$component->resolveRelativeStatePath('amount'),
                                fn (Get $get): bool => is_numeric($get('amount')),
                            )
                            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Calculated from the amount and rate — change it if your receipt shows a different VAT amount.')
                            ->tap(new ValidatesOnBlur),
                    ]),

                Section::make('Deductibility')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('category_id')
                            ->label('Category')
                            ->options(fn (): array => ExpenseCategory::query()
                                ->where('is_active', true)
                                ->orderBy('sort_order')
                                ->pluck('name_en', 'id')
                                ->all())
                            ->searchable()
                            ->live()
                            ->required(fn (Get $get): bool => self::isManualEntry($get))
                            ->helperText(fn (Get $get): ?string => self::categoryNotes($get('category_id')))
                            ->columnSpanFull()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $category = $state ? ExpenseCategory::find($state) : null;
                                if ($category !== null) {
                                    $set('deductibility', $category->default_deductibility->value);
                                    $set('deductible_pct', (float) $category->default_deductible_pct);
                                }
                            })
                            ->tap(new ValidatesOnBlur),
                        Radio::make('deductibility')
                            ->options(DeductibilityStatus::class)
                            ->descriptions([
                                DeductibilityStatus::FullyDeductible->value => 'Business cost claimable in full against your income.',
                                DeductibilityStatus::PartiallyDeductible->value => 'Half deductible — e.g. meals (Verpflegung) and entertainment.',
                                DeductibilityStatus::NotDeductible->value => 'Private cost — not claimable.',
                                DeductibilityStatus::Uncertain->value => 'Not sure yet — Settlo will flag it for review.',
                            ])
                            ->default(DeductibilityStatus::Uncertain->value)
                            ->live()
                            ->columnSpanFull()
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $percent = DeductibilityStatus::tryFrom((string) $state)?->defaultPercent();
                                if ($percent !== null) {
                                    $set('deductible_pct', $percent);
                                }
                            }),
                        TextInput::make('deductible_pct')
                            ->label('Deductible %')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->tap(new ValidatesOnBlur),
                        // The same BCMath helper that computes the stored
                        // deductible amount, so the preview cannot disagree
                        // with what is saved.
                        TextEntry::make('deductible_preview')
                            ->label('Deductible amount')
                            ->state(fn (Get $get): string => Money::format(
                                ExpenseService::deductibleAmount($get('amount'), $get('deductible_pct')),
                            )),
                        Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The fiscal year whose VAT rates apply to the form.
     */
    private static function fiscalYear(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }

    /**
     * The year's standard rate for VAT-registered businesses; 0 % otherwise,
     * since unregistered businesses can't reclaim input VAT. Both come from
     * the rate table, never from a hardcoded figure.
     */
    private static function defaultVatRate(): string
    {
        return CurrentWorkspace::entity()?->isVatRegistered()
            ? app(RateRepository::class)->standardVatRate(self::fiscalYear())
            : '0';
    }

    /**
     * The category's own deductibility note (the pro-rata rule behind its
     * default percentage), shown under the category picker.
     */
    private static function categoryNotes(mixed $categoryId): ?string
    {
        if (blank($categoryId)) {
            return null;
        }

        $category = ExpenseCategory::query()->whereKey($categoryId)->first(['notes', 'legal_basis']);

        return collect([$category?->notes, $category?->legal_basis])
            ->filter()
            ->implode(' · ') ?: null;
    }

    /**
     * Without a receipt the user is entering the expense by hand, so the
     * details the extraction would otherwise provide become mandatory.
     */
    private static function isManualEntry(Get $get): bool
    {
        return blank($get('receipt_path'));
    }

    /**
     * Derive the VAT amount from the VAT-inclusive total and the rate.
     */
    private static function fillVatAmount(Get $get, Set $set): void
    {
        $amount = $get('amount');
        $rate = $get('vat_rate');

        if (! is_numeric($amount) || ! is_numeric($rate)) {
            return;
        }

        $set('vat_amount', ExpenseService::vatFromGross((string) $amount, (string) $rate));
    }
}
