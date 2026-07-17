<?php

namespace App\Filament\Admin\Resources\VatConfigs\Pages;

use App\Filament\Admin\Resources\Concerns\AuditsTaxConfigEdit;
use App\Filament\Admin\Resources\VatConfigs\VatConfigResource;
use Filament\Resources\Pages\EditRecord;

class EditVatConfig extends EditRecord
{
    use AuditsTaxConfigEdit;

    protected static string $resource = VatConfigResource::class;

    protected function taxConfigTable(): string
    {
        return 'vat_configs';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
