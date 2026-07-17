<?php

namespace App\Filament\Admin\Resources\Escalations;

use App\Filament\Admin\Resources\Escalations\Pages\ListEscalations;
use App\Filament\Admin\Resources\Escalations\Pages\ViewEscalation;
use App\Filament\Admin\Resources\Escalations\Schemas\EscalationInfolist;
use App\Filament\Admin\Resources\Escalations\Tables\EscalationsTable;
use App\Models\AiEscalation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Global, read-only oversight of human-answer escalations across every firm.
 * Escalations are created and answered through the app/firm flows; this panel
 * only observes them — SLA breaches, response times and full threads — with no
 * write surface.
 */
class EscalationResource extends Resource
{
    protected static ?string $model = AiEscalation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Escalations';

    protected static ?string $modelLabel = 'Escalation';

    protected static ?int $navigationSort = 25;

    public static function infolist(Schema $schema): Schema
    {
        return EscalationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EscalationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEscalations::route('/'),
            'view' => ViewEscalation::route('/{record}'),
        ];
    }

    /**
     * The shared AiEscalationPolicy scopes view rights to the owning tenant, so a
     * superadmin would fail its `view` check. Access here is instead gated by the
     * superadmin-only admin panel; this resource is read-only oversight, so
     * viewing is authorized directly rather than through the tenant policy.
     */
    public static function canViewAny(): bool
    {
        return true;
    }

    public static function canView(Model $record): bool
    {
        return true;
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

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['accountant', 'accountingFirm', 'conversation.businessEntity', 'user']);
    }
}
