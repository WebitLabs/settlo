<?php

namespace App\Filament\Admin\Resources\Escalations\Pages;

use App\Filament\Admin\Resources\Escalations\EscalationResource;
use Filament\Resources\Pages\ListRecords;

class ListEscalations extends ListRecords
{
    protected static string $resource = EscalationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
