<?php

namespace App\Filament\Firm\Resources\Escalations\Pages;

use App\Filament\Firm\Resources\Escalations\EscalationResource;
use Filament\Resources\Pages\ListRecords;

class ListEscalations extends ListRecords
{
    protected static string $resource = EscalationResource::class;

    /**
     * Read-only queue: escalations are raised by owners in Ask Settlo, never
     * created from this panel.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
