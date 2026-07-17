<?php

namespace App\Filament\Admin\Resources\BusinessEntities;

use App\Filament\Admin\Resources\BusinessEntities\Pages\ListBusinessEntities;
use App\Filament\Admin\Resources\BusinessEntities\Pages\ViewBusinessEntity;
use App\Filament\Admin\Resources\BusinessEntities\Schemas\BusinessEntityInfolist;
use App\Filament\Admin\Resources\BusinessEntities\Tables\BusinessEntitiesTable;
use App\Models\BusinessEntity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Global, read-only oversight of every business entity on the platform. The
 * superadmin panel has no tenant, so this lists all entities without scoping;
 * there is deliberately no create/edit/delete surface over a tenant's books.
 */
class BusinessEntityResource extends Resource
{
    protected static ?string $model = BusinessEntity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Business entities';

    protected static ?string $modelLabel = 'Business entity';

    protected static ?string $pluralModelLabel = 'Business entities';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    public static function infolist(Schema $schema): Schema
    {
        return BusinessEntityInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BusinessEntitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBusinessEntities::route('/'),
            'view' => ViewBusinessEntity::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Eager load the owner, canton and invoice/expense counts used by the table
     * and detail view to avoid N+1 lookups.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['owner', 'canton'])
            ->withCount(['invoices', 'expenses']);
    }
}
