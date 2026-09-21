<?php

namespace App\Filament\Workspace\Resources\Clients\Schemas;

use App\Enums\Language;
use App\Filament\Shared\Fields\SwissAddressFields;
use App\Filament\Support\ValidatesOnBlur;
use App\Rules\SwissVatNumber;
use Closure;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class ClientForm
{
    /** Countries whose postal codes and VAT numbers follow the Swiss format. */
    private const array SWISS_FORMAT_COUNTRIES = ['CH', 'LI'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Client')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull()
                            ->tap(new ValidatesOnBlur),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255)
                            ->tap(new ValidatesOnBlur),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50)
                            ->tap(new ValidatesOnBlur),
                        TextInput::make('vat_number')
                            ->label('VAT number')
                            ->maxLength(50)
                            ->placeholder(fn (Get $get): string => self::usesSwissFormat($get) ? 'CHE-123.456.788 MWST' : 'DE123456789')
                            ->rule(new SwissVatNumber, fn (Get $get): bool => self::usesSwissFormat($get))
                            ->rule(fn (): Closure => self::foreignVatNumberRule(), fn (Get $get): bool => ! self::usesSwissFormat($get))
                            ->dehydrateStateUsing(fn (?string $state): ?string => blank($state)
                                ? null
                                : (SwissVatNumber::normalize($state) ?? strtoupper(str_replace(' ', '', $state))))
                            ->tap(new ValidatesOnBlur),
                    ]),

                Section::make('Address')
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        ...self::addressFields(),
                        TextInput::make('country_code')
                            ->label('Country')
                            ->helperText('Two-letter code, e.g. CH, LI, DE')
                            ->default('CH')
                            ->required()
                            ->length(2)
                            ->alpha()
                            ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state))
                            ->columnSpan(1)
                            ->tap(new ValidatesOnBlur),
                    ]),

                Section::make('Defaults')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        // Both columns are NOT NULL with a database default,
                        // and a default never applies to an explicit null — so
                        // clearing either field has to fail validation rather
                        // than reach the insert.
                        Select::make('default_language')
                            ->label('Language')
                            ->options(Language::class)
                            ->default('en')
                            ->required()
                            ->selectablePlaceholder(false)
                            ->helperText('Used for new invoices to this client.')
                            ->tap(new ValidatesOnBlur),
                        TextInput::make('default_payment_term_days')
                            ->label('Payment term')
                            ->integer()
                            ->required()
                            ->minValue(0)
                            ->maxValue(365)
                            ->suffix('days')
                            ->default(30)
                            ->helperText('Sets the due date on new invoices to this client.')
                            ->tap(new ValidatesOnBlur),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The shared Swiss address fields (search + postal code autofill for Swiss
     * clients), laid out on the four-column address grid.
     *
     * @return array<Field>
     */
    private static function addressFields(): array
    {
        $spans = [
            'street' => ['default' => 1, 'md' => 3],
            'street_number' => 1,
            'postal_code' => 1,
            'city' => ['default' => 1, 'md' => 2],
        ];

        return array_map(
            fn (Field $field): Field => isset($spans[$field->getName()]) ? $field->columnSpan($spans[$field->getName()]) : $field,
            SwissAddressFields::make(required: false, withCanton: false, isSwiss: fn (Get $get): bool => self::usesSwissFormat($get)),
        );
    }

    private static function usesSwissFormat(Get $get): bool
    {
        return in_array(strtoupper(trim((string) $get('country_code'))), self::SWISS_FORMAT_COUNTRIES, true);
    }

    /**
     * Foreign VAT IDs: a two-letter country prefix followed by 2–13 letters or digits
     * (spaces are ignored).
     */
    private static function foreignVatNumberRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $compact = strtoupper(str_replace(' ', '', (string) $value));

            if (preg_match('/^[A-Z]{2}[0-9A-Z]{2,13}$/', $compact) !== 1) {
                $fail('Enter a VAT number starting with the two-letter country code, e.g. DE123456789.');
            }
        };
    }
}
