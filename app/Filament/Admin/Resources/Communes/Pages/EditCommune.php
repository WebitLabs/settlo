<?php

namespace App\Filament\Admin\Resources\Communes\Pages;

use App\Filament\Admin\Resources\Communes\CommuneResource;
use App\Filament\Admin\Resources\Concerns\AuditsTaxConfigEdit;
use Filament\Resources\Pages\EditRecord;

class EditCommune extends EditRecord
{
    use AuditsTaxConfigEdit;

    protected static string $resource = CommuneResource::class;

    protected function taxConfigTable(): string
    {
        return 'communes';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
