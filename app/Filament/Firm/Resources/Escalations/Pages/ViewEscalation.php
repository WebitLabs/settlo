<?php

namespace App\Filament\Firm\Resources\Escalations\Pages;

use App\Filament\Firm\Resources\Escalations\EscalationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEscalation extends ViewRecord
{
    protected static string $resource = EscalationResource::class;

    /**
     * Read-only detail: answering happens through the table action, resolution
     * stays owner-side.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
