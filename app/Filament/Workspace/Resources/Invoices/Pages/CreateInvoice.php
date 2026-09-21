<?php

namespace App\Filament\Workspace\Resources\Invoices\Pages;

use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Services\Invoicing\InvoiceService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * The tenant, number, currency and status are server-controlled and guarded
     * against mass assignment, so the service writes them with forceFill rather
     * than taking them from the form payload — a crafted request cannot forge
     * them — inside the transaction that mints the invoice number.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(InvoiceService::class)->createDraft(Filament::getTenant(), $data);
    }

    protected function afterCreate(): void
    {
        app(InvoiceService::class)->recalculateTotals($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
