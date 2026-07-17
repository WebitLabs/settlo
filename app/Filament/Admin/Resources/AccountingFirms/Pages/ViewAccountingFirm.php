<?php

namespace App\Filament\Admin\Resources\AccountingFirms\Pages;

use App\Filament\Admin\Resources\AccountingFirms\AccountingFirmResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAccountingFirm extends ViewRecord
{
    protected static string $resource = AccountingFirmResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
