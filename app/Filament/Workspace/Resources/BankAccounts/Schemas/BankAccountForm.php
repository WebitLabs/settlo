<?php

namespace App\Filament\Workspace\Resources\BankAccounts\Schemas;

use App\Filament\Support\ValidatesOnBlur;
use App\Rules\ValidIban;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class BankAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Bank account')
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('account_name')
                            ->label('Label')
                            ->required()
                            ->maxLength(255)
                            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'A name to recognise this account, e.g. "Business account".')
                            ->tap(new ValidatesOnBlur),
                        TextInput::make('bank_name')
                            ->label('Bank')
                            ->required()
                            ->maxLength(255)
                            ->tap(new ValidatesOnBlur),
                        TextInput::make('iban')
                            ->label('IBAN')
                            ->required()
                            ->rule(new ValidIban)
                            ->mask('aa99 9999 9*** **** **** *')
                            ->placeholder('CH93 0076 2011 6238 5295 7')
                            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'A Swiss (CH) or Liechtenstein (LI) IBAN. The default account is printed on the Swiss QR-bill.')
                            ->columnSpanFull()
                            ->tap(new ValidatesOnBlur),
                        Select::make('currency_code')
                            ->label('Currency')
                            ->options(['CHF' => 'CHF', 'EUR' => 'EUR'])
                            ->default('CHF')
                            ->selectablePlaceholder(false)
                            ->required(),
                        Toggle::make('is_default')
                            ->label('Default account')
                            ->hintIcon(Heroicon::OutlinedInformationCircle, tooltip: 'Used as the creditor account on new invoices. Only one account can be the default.'),
                    ]),
            ]);
    }
}
