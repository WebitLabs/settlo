<?php

namespace App\Filament\Admin\Resources\VatConfigs\Pages;

use App\Filament\Admin\Resources\VatConfigs\VatConfigResource;
use Filament\Resources\Pages\ListRecords;

class ListVatConfigs extends ListRecords
{
    protected static string $resource = VatConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
