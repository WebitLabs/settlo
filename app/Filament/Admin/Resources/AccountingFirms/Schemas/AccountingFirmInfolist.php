<?php

namespace App\Filament\Admin\Resources\AccountingFirms\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AccountingFirmInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Firm')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('legal_name')
                            ->label('Legal name')
                            ->placeholder('—'),
                        TextEntry::make('uid')
                            ->label('UID')
                            ->placeholder('—'),
                        TextEntry::make('email')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('phone')
                            ->placeholder('—'),
                        IconEntry::make('is_active')
                            ->label('Active')
                            ->boolean(),
                    ]),
                Section::make('Address')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('street')
                            ->placeholder('—'),
                        TextEntry::make('postal_code')
                            ->label('Postal code')
                            ->placeholder('—'),
                        TextEntry::make('city')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}
