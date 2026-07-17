<?php

namespace App\Filament\Admin\Resources\SubscriptionPayments;

use App\Filament\Admin\Resources\SubscriptionPayments\Pages\ListSubscriptionPayments;
use App\Filament\Admin\Resources\SubscriptionPayments\Pages\ViewSubscriptionPayment;
use App\Filament\Admin\Resources\SubscriptionPayments\Schemas\SubscriptionPaymentInfolist;
use App\Filament\Admin\Resources\SubscriptionPayments\Tables\SubscriptionPaymentsTable;
use App\Models\SubscriptionPayment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only ledger of every subscription charge taken by the billing gateway.
 * Payments are immutable financial records: the panel exposes list and view
 * only, with no create, edit or delete surface.
 */
class SubscriptionPaymentResource extends Resource
{
    protected static ?string $model = SubscriptionPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $modelLabel = 'Payment';

    protected static ?int $navigationSort = 50;

    public static function infolist(Schema $schema): Schema
    {
        return SubscriptionPaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SubscriptionPaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubscriptionPayments::route('/'),
            'view' => ViewSubscriptionPayment::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['subscription.user', 'plan']);
    }
}
