<?php

namespace App\Filament\App\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Services\Invoicing\InvoiceService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    /**
     * The tenant, number, currency and status are server-controlled and guarded
     * against mass assignment, so they are written with forceFill rather than
     * through the form payload — a crafted request cannot forge them.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $entity = Filament::getTenant();

        $invoice = new Invoice;
        $invoice->fill($data);
        $invoice->forceFill([
            'business_entity_id' => $entity->getKey(),
            'currency_code' => $entity->default_currency ?: 'CHF',
            'status' => InvoiceStatus::Draft->value,
            'invoice_number' => app(InvoiceService::class)->nextInvoiceNumber($entity),
        ]);
        $invoice->save();

        return $invoice;
    }

    protected function afterCreate(): void
    {
        app(InvoiceService::class)->recalculateTotals($this->record);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
