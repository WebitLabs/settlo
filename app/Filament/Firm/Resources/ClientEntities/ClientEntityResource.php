<?php

namespace App\Filament\Firm\Resources\ClientEntities;

use App\Filament\Firm\Resources\ClientEntities\Pages\ListClientEntities;
use App\Filament\Firm\Resources\ClientEntities\Pages\ViewClientEntity;
use App\Filament\Firm\Resources\ClientEntities\RelationManagers\ExpensesRelationManager;
use App\Filament\Firm\Resources\ClientEntities\RelationManagers\InvoicesRelationManager;
use App\Filament\Firm\Resources\ClientEntities\Schemas\ClientEntityInfolist;
use App\Filament\Firm\Resources\ClientEntities\Tables\ClientEntitiesTable;
use App\Models\BusinessEntity;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only view of the businesses a firm has been assigned to manage. There is
 * no create/edit/delete surface: an accountant may only inspect a client's
 * books through this resource, never mutate them.
 */
class ClientEntityResource extends Resource
{
    protected static ?string $model = BusinessEntity::class;

    /**
     * BusinessEntity has no singular ownership relationship to a firm — access
     * is granted through AccountantAssignment rows, so Filament's automatic
     * tenant scoping cannot apply. getEloquentQuery() enforces the
     * active-assignment boundary explicitly instead.
     */
    protected static bool $isScopedToTenant = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Clients';

    protected static ?string $modelLabel = 'Client';

    protected static ?string $pluralModelLabel = 'Clients';

    protected static ?string $recordTitleAttribute = 'name';

    public static function infolist(Schema $schema): Schema
    {
        return ClientEntityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ClientEntitiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            InvoicesRelationManager::class,
            ExpensesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientEntities::route('/'),
            'view' => ViewClientEntity::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Tenant isolation. Only businesses linked to the current firm tenant by an
     * active (non-revoked) assignment are ever returned, and the aggregates and
     * relations needed by the table are eager loaded to avoid N+1 queries. When
     * no firm tenant is resolved the query is forced empty (default-deny).
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $firmId = $tenant->getKey();
        $year = (int) config('settlo.current_fiscal_year', now()->year);

        return parent::getEloquentQuery()
            ->whereHas('accountantAssignments', fn (Builder $query) => $query
                ->whereNull('revoked_at')
                ->where('accounting_firm_id', $firmId))
            ->with([
                'owner',
                'canton',
                'accountantAssignments' => fn ($query) => $query
                    ->whereNull('revoked_at')
                    ->where('accounting_firm_id', $firmId)
                    ->with('accountant'),
            ])
            ->withSum([
                'invoices as revenue_ytd' => fn (Builder $query) => $query
                    ->countsAsRevenue()
                    ->whereYear('issue_date', $year),
            ], 'total');
    }
}
