<?php

namespace App\Filament\Firm\Resources\Escalations;

use App\Filament\Firm\Resources\Escalations\Pages\ListEscalations;
use App\Filament\Firm\Resources\Escalations\Pages\ViewEscalation;
use App\Filament\Firm\Resources\Escalations\Schemas\EscalationInfolist;
use App\Filament\Firm\Resources\Escalations\Tables\EscalationsTable;
use App\Models\AiEscalation;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The firm's accountant-escalation queue. Every row is a question an owner asked
 * Ask Settlo and forwarded to their accounting firm. Accountants may claim and
 * answer escalations here; resolution stays owner-side. The queue is read/answer
 * only — there is no create/edit/delete surface.
 */
class EscalationResource extends Resource
{
    protected static ?string $model = AiEscalation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Escalations';

    protected static ?string $modelLabel = 'Escalation';

    protected static ?string $pluralModelLabel = 'Escalations';

    protected static ?string $recordTitleAttribute = 'user_question';

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

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Tenant isolation. An escalation is visible only when the business that
     * raised it (derived through the conversation) is actively assigned to the
     * current firm tenant — the same active-assignment boundary the client-books
     * resource and policies enforce. A revoked assignment removes the escalation
     * from the queue. When no firm tenant is resolved the query is forced empty.
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        $firmId = $tenant->getKey();

        return parent::getEloquentQuery()
            ->whereHas('conversation.businessEntity.accountantAssignments', fn (Builder $query) => $query
                ->whereNull('revoked_at')
                ->where('accounting_firm_id', $firmId))
            ->with([
                'conversation.businessEntity',
                'accountant',
            ])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 WHEN status = 'in_progress' THEN 1 ELSE 2 END")
            ->orderBy('sla_deadline');
    }
}
