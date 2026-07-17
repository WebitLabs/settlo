<?php

namespace App\Filament\Admin\Resources\BusinessEntities\Pages;

use App\Filament\Admin\Resources\BusinessEntities\BusinessEntityResource;
use Filament\Resources\Pages\ListRecords;

class ListBusinessEntities extends ListRecords
{
    protected static string $resource = BusinessEntityResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
