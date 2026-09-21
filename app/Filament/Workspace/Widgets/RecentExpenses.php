<?php

namespace App\Filament\Workspace\Widgets;

use App\Filament\Workspace\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * The five latest expenses of the active business.
 */
class RecentExpenses extends TableWidget
{
    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent expenses')
            ->query(fn () => Expense::query()
                ->with('category')
                ->where('business_entity_id', Filament::getTenant()?->getKey())
                ->latest('expense_date')
                ->latest()
                ->limit(5))
            ->paginated(false)
            ->recordUrl(fn (Expense $record): string => ExpenseResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('No expenses yet')
            ->columns([
                TextColumn::make('vendor')
                    ->label('Vendor')
                    ->weight('medium')
                    ->placeholder('—')
                    ->description(fn (Expense $record): ?string => $record->expense_date?->format('d.m.Y')),
                TextColumn::make('amount')
                    ->formatStateUsing(fn (Expense $record): string => Money::format($record->amount, $record->currency_code))
                    ->alignEnd(),
                TextColumn::make('status')->badge(),
            ]);
    }
}
