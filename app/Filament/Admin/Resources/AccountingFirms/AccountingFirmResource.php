<?php

namespace App\Filament\Admin\Resources\AccountingFirms;

use App\Filament\Admin\Resources\AccountingFirms\Pages\CreateAccountingFirm;
use App\Filament\Admin\Resources\AccountingFirms\Pages\ListAccountingFirms;
use App\Filament\Admin\Resources\AccountingFirms\Pages\ViewAccountingFirm;
use App\Filament\Admin\Resources\AccountingFirms\RelationManagers\AssignmentsRelationManager;
use App\Filament\Admin\Resources\AccountingFirms\RelationManagers\InvitationsRelationManager;
use App\Filament\Admin\Resources\AccountingFirms\RelationManagers\MembersRelationManager;
use App\Filament\Admin\Resources\AccountingFirms\Schemas\AccountingFirmForm;
use App\Filament\Admin\Resources\AccountingFirms\Schemas\AccountingFirmInfolist;
use App\Filament\Admin\Resources\AccountingFirms\Tables\AccountingFirmsTable;
use App\Models\AccountingFirm;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Firm administration for superadmins. Beyond global oversight, this is where a
 * firm is provisioned: creating a firm seeds it with its first owner-member (an
 * existing accountant) so the firm has someone who can run it from day one. Team,
 * client assignments and invitations are exposed read-only, with a single guarded
 * action to revoke an accountant's access to a client.
 */
class AccountingFirmResource extends Resource
{
    protected static ?string $model = AccountingFirm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Firms';

    protected static ?string $navigationLabel = 'Accounting firms';

    protected static ?string $modelLabel = 'Accounting firm';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 60;

    public static function form(Schema $schema): Schema
    {
        return AccountingFirmForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AccountingFirmInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountingFirmsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
            AssignmentsRelationManager::class,
            InvitationsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAccountingFirms::route('/'),
            'create' => CreateAccountingFirm::route('/create'),
            'view' => ViewAccountingFirm::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount(['members', 'activeAssignments']);
    }
}
