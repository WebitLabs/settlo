<?php

namespace App\Filament\App\Resources\BankAccounts;

use App\Filament\App\Resources\BankAccounts\Pages\CreateBankAccount;
use App\Filament\App\Resources\BankAccounts\Pages\EditBankAccount;
use App\Filament\App\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\App\Resources\BankAccounts\Schemas\BankAccountForm;
use App\Filament\App\Resources\BankAccounts\Tables\BankAccountsTable;
use App\Models\BankAccount;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'account_name';

    public static function form(Schema $schema): Schema
    {
        return BankAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BankAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankAccounts::route('/'),
            'create' => CreateBankAccount::route('/create'),
            'edit' => EditBankAccount::route('/{record}/edit'),
        ];
    }

    /**
     * Explicit tenant isolation: every query is constrained to the active
     * business, so bank accounts from other tenants are never returned.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->when(
                Filament::getTenant(),
                fn (Builder $query, $tenant) => $query->where('business_entity_id', $tenant->getKey()),
            );
    }
}
