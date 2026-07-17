<?php

namespace App\Filament\Firm\Resources\Members;

use App\Filament\Firm\Resources\Members\Pages\ListMembers;
use App\Filament\Firm\Resources\Members\Tables\MembersTable;
use App\Models\AccountingFirmMember;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * The firm's team. Any member may view the roster, but only firm owners may add,
 * promote, demote, or remove members. Every mutation re-checks firm ownership so
 * a non-owner can never manage the team via a crafted request.
 */
class MemberResource extends Resource
{
    protected static ?string $model = AccountingFirmMember::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Team';

    protected static ?string $modelLabel = 'Team member';

    protected static ?string $pluralModelLabel = 'Team';

    public static function table(Table $table): Table
    {
        return MembersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMembers::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Whether the authenticated user is an owner of the current firm tenant.
     * Gates every management action in this resource.
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
     * Number of owners currently on the firm — used to protect the last owner.
     */
    public static function ownerCount(): int
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return 0;
        }

        return AccountingFirmMember::query()
            ->where('accounting_firm_id', $tenant->getKey())
            ->where('is_owner', true)
            ->count();
    }

    /**
     * Tenant isolation: only members of the current firm tenant are returned.
     * When no tenant is resolved the query is forced empty.
     */
    public static function getEloquentQuery(): Builder
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->where('accounting_firm_id', $tenant->getKey())
            ->with('user');
    }
}
