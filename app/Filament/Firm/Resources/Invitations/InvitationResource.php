<?php

namespace App\Filament\Firm\Resources\Invitations;

use App\Filament\Firm\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Firm\Resources\Invitations\Tables\InvitationsTable;
use App\Models\FirmClientInvitation;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Client invitations a firm has sent. Invitations are created and re-sent
 * through actions (which mint a token and email it); the token itself is never
 * shown here — only its lifecycle state.
 */
class InvitationResource extends Resource
{
    protected static ?string $model = FirmClientInvitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static ?string $navigationLabel = 'Invitations';

    protected static ?string $modelLabel = 'Invitation';

    protected static ?string $pluralModelLabel = 'Invitations';

    protected static ?string $recordTitleAttribute = 'email';

    public static function table(Table $table): Table
    {
        return InvitationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvitations::route('/'),
        ];
    }

    /**
     * Tenant isolation: only invitations belonging to the current firm tenant
     * are ever returned. When no tenant is resolved the query is forced empty.
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('accounting_firm_id', $tenant->getKey());
    }
}
