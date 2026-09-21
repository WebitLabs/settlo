<?php

namespace App\Filament\Personal\Resources\Businesses;

use App\Filament\Personal\Resources\Businesses\Pages\ListBusinesses;
use App\Filament\Personal\Resources\Businesses\Tables\BusinessesTable;
use App\Models\BusinessEntity;
use App\Services\Reporting\BusinessMetrics;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * "My businesses": the owner's business workspaces with their plan and
 * year-to-date revenue. Businesses are created with the "Set up a business"
 * stepper and managed inside their workspace, so this resource only lists.
 */
class BusinessResource extends Resource
{
    protected static ?string $model = BusinessEntity::class;

    protected static ?string $slug = 'businesses';

    protected static ?string $modelLabel = 'business';

    protected static ?string $pluralModelLabel = 'businesses';

    protected static ?string $navigationLabel = 'My businesses';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Businesses';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return (Filament::auth()->user()?->isOwner() ?? false) && parent::canAccess();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return BusinessesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBusinesses::route('/'),
        ];
    }

    /**
     * Only the signed-in owner's businesses, with their subscription and the
     * year-to-date revenue (excl. VAT).
     */
    public static function getEloquentQuery(): Builder
    {
        $year = BusinessMetrics::currentYear();

        return parent::getEloquentQuery()
            ->where('owner_id', Filament::auth()->id())
            ->with(['subscription.plan'])
            ->withSum([
                'invoices as revenue_ytd' => fn (Builder $query): Builder => $query
                    ->countsAsRevenue()
                    ->where('issue_date', '>=', "{$year}-01-01")
                    ->where('issue_date', '<', ($year + 1).'-01-01'),
            ], 'subtotal');
    }
}
