<?php

namespace App\Filament\Firm\Resources\Invitations;

use App\Filament\Firm\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Firm\Resources\Invitations\Tables\InvitationsTable;
use App\Models\AccountingFirmMember;
use App\Models\FirmClientInvitation;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

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
     * Inviting a client is a firm-owner action, like every other team change.
     */
    public static function canCreate(): bool
    {
        return static::currentUserIsFirmOwner();
    }

    /**
     * Whether the authenticated user is an owner of the current firm tenant.
     * Gates the invite/resend/revoke actions in this resource.
     */
    public static function currentUserIsFirmOwner(): bool
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return false;
        }

        return AccountingFirmMember::query()
            ->where('accounting_firm_id', $tenant->getKey())
            ->where('user_id', Auth::id())
            ->where('is_owner', true)
            ->exists();
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
