<?php

namespace App\Filament\App\Resources\Expenses\Schemas;

use App\Enums\DeductibilityStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ExpenseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Receipt')
                    ->schema([
                        FileUpload::make('receipt_path')
                            ->label('Receipt')
                            ->disk('receipts')
                            ->visibility('private')
                            ->directory('receipts')
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                            ->maxSize(20480)
                            ->downloadable()
                            ->helperText('Upload a photo or PDF and Settlo will read the details for you.')
                            ->columnSpanFull(),
                        Placeholder::make('ai_suggestion')
                            ->label('Settlo read')
                            ->visible(fn (?Expense $record): bool => $record?->ai_suggested_category_id !== null)
                            ->content(function (?Expense $record): string {
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
                    ->columns(2)
                    ->schema([
                        TextInput::make('vendor')
                            ->maxLength(255),
                        DatePicker::make('expense_date')
                            ->required()
                            ->default(now())
                            ->native(false),
                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->prefix('CHF')
                            ->minValue(0)
                            ->default(0)
                            ->helperText('Leave at 0 when uploading a receipt — Settlo fills it in.')
                            ->live(onBlur: true),
                        TextInput::make('vat_amount')
                            ->label('VAT amount')
                            ->numeric()
                            ->prefix('CHF')
                            ->minValue(0)
                            ->default(0),
                        TextInput::make('vat_rate')
                            ->label('VAT rate %')
                            ->numeric()
                            ->minValue(0)
                            ->default(0),
                    ]),

                Section::make('Deductibility')
                    ->columns(2)
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
                            ->afterStateUpdated(function ($state, Set $set): void {
                                $category = $state ? ExpenseCategory::find($state) : null;
                                if ($category !== null) {
                                    $set('deductibility', $category->default_deductibility->value);
                                    $set('deductible_pct', (float) $category->default_deductible_pct);
                                }
                            }),
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
                            ->live(onBlur: true),
                        Placeholder::make('deductible_preview')
                            ->label('Deductible amount')
                            ->content(fn (Get $get): string => 'CHF '.number_format(
                                (float) $get('amount') * (float) $get('deductible_pct') / 100,
                                2, '.', "'"
                            )),
                        Textarea::make('notes')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
