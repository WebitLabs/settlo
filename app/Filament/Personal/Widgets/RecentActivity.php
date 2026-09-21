<?php

namespace App\Filament\Personal\Widgets;

use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The latest invoices across all of the owner's businesses.
 */
class RecentActivity extends TableWidget
{
    protected static ?int $sort = 5;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        $user = Filament::auth()->user();

        return ($user?->isOwner() ?? false) && $user->ownedEntities()->exists();
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent invoices')
            ->query(fn () => Invoice::query()
                ->whereIn('business_entity_id', Filament::auth()->user()?->ownedEntities()->select('id') ?? [])
                ->with(['businessEntity'])
                ->latest('issue_date')
                ->latest()
                ->limit(5))
            ->paginated(false)
            ->recordUrl(fn (Invoice $record): string => InvoiceResource::getUrl('view', ['record' => $record], panel: 'workspace', tenant: $record->businessEntity))
            ->emptyStateHeading('No invoices yet')
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Invoice')
                    ->weight('medium')
                    ->description(fn (Invoice $record): string => (string) $record->businessEntity?->name),
                TextColumn::make('total')->money('chf')->alignEnd(),
                TextColumn::make('status')->badge(),
            ]);
    }
}
