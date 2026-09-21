<?php

namespace App\Filament\Workspace\Widgets;

use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * The five latest invoices of the active business, each linking to its view page.
 */
class RecentInvoices extends BaseWidget
{
    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent invoices')
            ->query(
                Invoice::query()
                    ->with('client')
                    ->where('business_entity_id', Filament::getTenant()?->getKey())
                    ->latest('issue_date')
                    ->limit(5)
            )
            ->paginated(false)
            ->recordUrl(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No invoices yet')
            ->columns([
                TextColumn::make('invoice_number')->label('Number')->weight('medium'),
                TextColumn::make('client.name')->label('Client'),
                TextColumn::make('issue_date')->date('d.m.Y'),
                TextColumn::make('total')
                    ->formatStateUsing(fn (Invoice $record): string => Money::format($record->total, $record->currency_code))
                    ->alignEnd(),
                TextColumn::make('status')->badge(),
            ]);
    }
}
