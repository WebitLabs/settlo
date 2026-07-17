<?php

namespace App\Filament\Admin\Resources\CantonFiscalConfigs\Pages;

use App\Filament\Admin\Resources\CantonFiscalConfigs\CantonFiscalConfigResource;
use App\Filament\Admin\Resources\Concerns\AuditsTaxConfigEdit;
use Filament\Resources\Pages\EditRecord;

class EditCantonFiscalConfig extends EditRecord
{
    use AuditsTaxConfigEdit;

    protected static string $resource = CantonFiscalConfigResource::class;

    protected function taxConfigTable(): string
    {
        return 'canton_fiscal_configs';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
