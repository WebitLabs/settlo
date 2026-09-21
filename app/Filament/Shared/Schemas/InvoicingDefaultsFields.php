<?php

namespace App\Filament\Shared\Schemas;

use App\Enums\Language;
use App\Filament\Support\ProfileFields;
use App\Filament\Support\ValidatesOnBlur;
use App\Rules\ValidIban;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * The invoicing defaults (IBAN, bank, payment terms, language, numbering),
 * shared by the "Set up a business" stepper and the business settings.
 */
final class InvoicingDefaultsFields
{
    /**
     * @param  bool  $withBankName  Ask for the bank name (used for the default bank account).
     * @param  bool  $withInvoiceNotes  Offer the default invoice notes (settings only).
     * @param  int|null  $debounceMs  Validate while typing (instead of on blur) with this debounce.
     * @return array<Component|Select|TextInput|Textarea>
     */
    public static function components(
        bool $withBankName = false,
        bool $ibanRequired = true,
        bool $withInvoiceNotes = false,
        ?int $debounceMs = null,
    ): array {
        $validates = new ValidatesOnBlur(debounceMs: $debounceMs);

        return array_values(array_filter([
            ProfileFields::iban()
                ->required($ibanRequired)
                ->rule(new ValidIban)
                ->columnSpan($withBankName ? 1 : 'full')
                ->tap($validates),
            $withBankName
                ? TextInput::make('bank_name')
                    ->label('Bank name')
                    ->placeholder('e.g. UBS')
                    ->maxLength(255)
                    ->tap($validates)
                : null,
            $debounceMs === null ? ProfileFields::paymentTerms() : ProfileFields::paymentTerms()->tap($validates),
            Select::make('default_language')
                ->label('Invoice language')
                ->options(Language::class)
                ->default(Language::English->value)
                ->selectablePlaceholder(false)
                ->required(),
            TextInput::make('invoice_number_prefix')
                ->label('Invoice number prefix')
                ->default('INV-')
                ->required()
                ->maxLength(20)
                ->alphaDash()
                ->tap($validates),
            $withInvoiceNotes
                ? Textarea::make('default_invoice_notes')
                    ->label('Default invoice notes')
                    ->rows(3)
                    ->columnSpanFull()
                : null,
        ]));
    }
}
