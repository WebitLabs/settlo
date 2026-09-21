<?php

namespace App\Filament\Workspace\Resources\Expenses;

use App\Enums\ExpenseStatus;
use App\Filament\Workspace\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Workspace\Resources\Expenses\Pages\EditExpense;
use App\Filament\Workspace\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Workspace\Resources\Expenses\Schemas\ExpenseForm;
use App\Filament\Workspace\Resources\Expenses\Tables\ExpensesTable;
use App\Models\Expense;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'vendor';

    /**
     * Expenses awaiting confirmation don't count for tax or VAT yet, so the
     * navigation shows how many are waiting.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return null;
        }

        $count = Expense::query()
            ->where('business_entity_id', $tenant->getKey())
            ->where('status', ExpenseStatus::PendingReview->value)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): string
    {
        return 'Awaiting confirmation';
    }

    public static function form(Schema $schema): Schema
    {
        return ExpenseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ExpensesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'edit' => EditExpense::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    /**
     * Explicit tenant isolation: expenses are always constrained to the active
     * business, independent of Filament auto-scoping.
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
