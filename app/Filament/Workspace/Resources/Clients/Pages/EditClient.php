<?php

namespace App\Filament\Workspace\Resources\Clients\Pages;

use App\Filament\Workspace\Resources\Clients\ClientResource;
use App\Filament\Workspace\Resources\Clients\Tables\ClientsTable;
use App\Models\Client;
use App\Models\Invoice;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(fn (Client $record): string => ClientsTable::deleteWarning(
                    Invoice::withTrashed()->where('client_id', $record->getKey())->count()
                )),
            ForceDeleteAction::make()
                ->modalDescription(ClientsTable::FORCE_DELETE_WARNING),
            RestoreAction::make(),
        ];
    }
}
