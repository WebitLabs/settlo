<?php

namespace App\Filament\Firm\Resources\ClientEntities\Pages;

use App\Filament\Firm\Resources\ClientEntities\ClientEntityResource;
use Filament\Resources\Pages\ListRecords;

class ListClientEntities extends ListRecords
{
    protected static string $resource = ClientEntityResource::class;

    /**
     * Read-only: a firm never creates a client from this panel — clients arrive
     * through the invitation/assignment flow.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
