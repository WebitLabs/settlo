<?php

namespace App\Filament\Admin\Resources\FederalTaxBrackets\Pages;

use App\Filament\Admin\Resources\FederalTaxBrackets\FederalTaxBracketResource;
use Filament\Resources\Pages\ListRecords;

class ListFederalTaxBrackets extends ListRecords
{
    protected static string $resource = FederalTaxBracketResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
