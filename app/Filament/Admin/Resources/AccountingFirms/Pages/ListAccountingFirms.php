<?php

namespace App\Filament\Admin\Resources\AccountingFirms\Pages;

use App\Filament\Admin\Resources\AccountingFirms\AccountingFirmResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountingFirms extends ListRecords
{
    protected static string $resource = AccountingFirmResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
