<?php

namespace App\Filament\App\Resources\Invoices\Pages;

use App\Enums\InvoiceStatus;
use App\Filament\App\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Services\Invoicing\InvoicePdfService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Download PDF')
                ->icon('heroicon-m-arrow-down-tray')
                ->visible(fn (Invoice $record): bool => $record->status !== InvoiceStatus::Draft)
                ->action(fn (Invoice $record) => app(InvoicePdfService::class)->download($record)),
        ];
    }
}
