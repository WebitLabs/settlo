<?php

namespace App\Filament\Admin\Resources\CantonFiscalConfigs\Pages;

use App\Filament\Admin\Resources\CantonFiscalConfigs\CantonFiscalConfigResource;
use Filament\Resources\Pages\ListRecords;

class ListCantonFiscalConfigs extends ListRecords
{
    protected static string $resource = CantonFiscalConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
