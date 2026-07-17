<?php

namespace App\Filament\App\Resources\BankAccounts\Schemas;

use App\Rules\ValidIban;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Bank account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('account_name')
                            ->label('Label')
                            ->required()
                            ->maxLength(255)
                            ->helperText('A name to recognise this account, e.g. "Business account".'),
                        TextInput::make('bank_name')
                            ->label('Bank')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('iban')
                            ->label('IBAN')
                            ->required()
                            ->rule(new ValidIban)
                            ->columnSpanFull(),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(['CHF' => 'CHF', 'EUR' => 'EUR'])
                            ->default('CHF')
                            ->selectablePlaceholder(false)
                            ->required(),
                        Toggle::make('is_default')
                            ->label('Default account')
                            ->helperText('Used as the creditor account on new invoices. Only one account can be the default.'),
                    ]),
            ]);
    }
}
