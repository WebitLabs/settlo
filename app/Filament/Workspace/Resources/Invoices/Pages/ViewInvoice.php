<?php

namespace App\Filament\Workspace\Resources\Invoices\Pages;

use App\Filament\Workspace\Resources\Invoices\Actions\InvoiceActions;
use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only invoice with its whole lifecycle one click away: send (drafts),
 * mark paid, edit (drafts), PDF, and cancel / delete in the overflow menu.
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            InvoiceActions::send()
                ->button()
                ->after(fn (ViewInvoice $livewire) => $livewire->getRecord()->refresh()),
            InvoiceActions::markPaid()
                ->button()
                ->after(fn (ViewInvoice $livewire) => $livewire->getRecord()->refresh()),
            EditAction::make()
                ->visible(fn (Invoice $record): bool => $record->status->isEditable()),
            InvoiceActions::pdf(),
            ActionGroup::make([
                InvoiceActions::cancel()
                    ->after(fn (ViewInvoice $livewire) => $livewire->getRecord()->refresh()),
                DeleteAction::make(),
            ]),
        ];
    }
}
