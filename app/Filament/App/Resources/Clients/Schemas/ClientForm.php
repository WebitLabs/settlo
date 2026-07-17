<?php

namespace App\Filament\App\Resources\Clients\Schemas;

use App\Enums\Language;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Client')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('vat_number')
                            ->label('VAT number')
                            ->maxLength(50),
                    ]),

                Section::make('Address')
                    ->columns(4)
                    ->schema([
                        TextInput::make('street')
                            ->columnSpan(3),
                        TextInput::make('street_number')
                            ->label('No.')
                            ->columnSpan(1),
                        TextInput::make('postal_code')
                            ->label('Postal code')
                            ->columnSpan(1),
                        TextInput::make('city')
                            ->columnSpan(2),
                        TextInput::make('country_code')
                            ->label('Country')
                            ->default('CH')
                            ->maxLength(2)
                            ->columnSpan(1),
                    ]),

                Section::make('Defaults')
                    ->columns(2)
                    ->schema([
                        Select::make('default_language')
                            ->label('Language')
                            ->options(Language::class)
                            ->default('en'),
                        TextInput::make('default_payment_term_days')
                            ->label('Payment term (days)')
                            ->numeric()
                            ->minValue(0)
                            ->default(30),
                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
