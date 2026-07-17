<?php

namespace App\Filament\Admin\Resources\Escalations\Pages;

use App\Filament\Admin\Resources\Escalations\EscalationResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEscalation extends ViewRecord
{
    protected static string $resource = EscalationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
