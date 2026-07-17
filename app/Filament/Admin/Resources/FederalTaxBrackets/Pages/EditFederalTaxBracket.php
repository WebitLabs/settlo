<?php

namespace App\Filament\Admin\Resources\FederalTaxBrackets\Pages;

use App\Filament\Admin\Resources\Concerns\AuditsTaxConfigEdit;
use App\Filament\Admin\Resources\FederalTaxBrackets\FederalTaxBracketResource;
use Filament\Resources\Pages\EditRecord;

class EditFederalTaxBracket extends EditRecord
{
    use AuditsTaxConfigEdit;

    protected static string $resource = FederalTaxBracketResource::class;

    protected function taxConfigTable(): string
    {
        return 'federal_tax_brackets';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
