<?php

namespace App\Filament\Admin\Resources\AccountingFirms\Schemas;

use App\Enums\UserRole;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

class AccountingFirmForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Firm')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('legal_name')
                            ->label('Legal name')
                            ->maxLength(255),
                        TextInput::make('uid')
                            ->label('UID')
                            ->placeholder('CHE-123.456.789')
                            ->maxLength(255),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                    ]),

                Section::make('Address')
                    ->columns(2)
                    ->schema([
                        TextInput::make('street')
                            ->maxLength(255),
                        TextInput::make('postal_code')
                            ->label('Postal code')
                            ->maxLength(255),
                        TextInput::make('city')
                            ->maxLength(255),
                    ]),

                Section::make('First owner')
                    ->description('An existing accountant who will own and administer the firm.')
                    ->schema([
                        TextInput::make('owner_email')
                            ->label('Owner accountant email')
                            ->email()
                            ->required()
                            // The first owner must be an existing, non-deleted user with
                            // the accountant role — owners are never created here.
                            ->rule(Rule::exists('users', 'email')
                                ->where('role', UserRole::Accountant->value)
                                ->whereNull('deleted_at'))
                            ->validationMessages([
                                'exists' => 'No accountant account exists with this email.',
                            ]),
                    ]),
            ]);
    }
}
