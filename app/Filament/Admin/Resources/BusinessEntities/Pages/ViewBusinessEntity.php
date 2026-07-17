<?php

namespace App\Filament\Admin\Resources\BusinessEntities\Pages;

use App\Filament\Admin\Resources\BusinessEntities\BusinessEntityResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBusinessEntity extends ViewRecord
{
    protected static string $resource = BusinessEntityResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
