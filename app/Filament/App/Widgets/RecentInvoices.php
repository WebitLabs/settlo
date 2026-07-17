<?php

namespace App\Filament\App\Widgets;

use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentInvoices extends BaseWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent invoices')
            ->query(
                Invoice::query()
                    ->where('business_entity_id', Filament::getTenant()?->getKey())
                    ->latest('issue_date')
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                TextColumn::make('invoice_number')->label('Number')->weight('medium'),
                TextColumn::make('client.name')->label('Client'),
                TextColumn::make('issue_date')->date('d.m.Y'),
                TextColumn::make('total')->money('chf')->alignEnd(),
                TextColumn::make('status')->badge(),
            ]);
    }
}
