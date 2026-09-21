<?php

namespace App\Filament\Workspace\Resources\Invoices\Pages;

use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Services\Invoicing\InvoiceService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(InvoiceService::class)->recalculateTotals($this->record);
    }

    /**
     * Back to the view page, where Send / Download PDF are one click away.
     */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
