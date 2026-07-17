<?php

namespace App\Filament\Admin\Resources\Cantons\Pages;

use App\Filament\Admin\Resources\Cantons\CantonResource;
use Filament\Resources\Pages\ListRecords;

class ListCantons extends ListRecords
{
    protected static string $resource = CantonResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
