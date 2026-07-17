<?php

namespace App\Filament\Firm\Resources\ClientEntities\Pages;

use App\Filament\Firm\Resources\ClientEntities\ClientEntityResource;
use Filament\Resources\Pages\ViewRecord;

class ViewClientEntity extends ViewRecord
{
    protected static string $resource = ClientEntityResource::class;

    /**
     * Read-only: no edit/delete surface on a client's books.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
