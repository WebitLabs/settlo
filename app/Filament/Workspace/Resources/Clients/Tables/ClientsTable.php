<?php

namespace App\Filament\Workspace\Resources\Clients\Tables;

use App\Models\Client;
use App\Models\Invoice;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ClientsTable
{
    public const string FORCE_DELETE_WARNING = "Permanently deletes the client. Clients with invoices can't be deleted permanently.";

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->toggleable()
                    ->icon('heroicon-m-envelope'),
                TextColumn::make('city')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('invoices_count')
                    ->label('Invoices')
                    ->counts('invoices')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription(fn (Client $record): string => self::deleteWarning(self::invoiceCount([$record->getKey()]))),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription(fn (Collection $records): string => self::deleteWarning(self::invoiceCount($records->modelKeys()))),
                    ForceDeleteBulkAction::make()
                        ->authorizeIndividualRecords('forceDelete')
                        ->modalDescription(self::FORCE_DELETE_WARNING),
                    RestoreBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    /**
     * Explains what happens to a client's invoices when the client is deleted.
     */
    public static function deleteWarning(int $invoiceCount): string
    {
        if ($invoiceCount === 0) {
            return 'This client will be moved to the trash. You can restore it later.';
        }

        $invoices = $invoiceCount === 1 ? '1 invoice' : "{$invoiceCount} invoices";

        return "This client has {$invoices}. The invoices are kept and will still show this client. The client is moved to the trash and can be restored.";
    }

    /**
     * Invoices (including trashed ones) that reference the given clients.
     *
     * @param  array<int, string>  $clientIds
     */
    private static function invoiceCount(array $clientIds): int
    {
        return Invoice::withTrashed()->whereIn('client_id', $clientIds)->count();
    }
}
