<?php

namespace App\Filament\Firm\Widgets;

use App\Models\AiEscalation;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * The five most recently raised escalations for the current firm tenant. Scoped
 * to businesses the firm is actively assigned to — the same boundary the full
 * escalation queue uses — so nothing from another firm is ever shown.
 */
class RecentEscalations extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Recent escalations')
            ->query(fn (): Builder => $this->escalationsForFirm())
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Raised')
                    ->dateTime('d.m.Y H:i'),
                TextColumn::make('client')
                    ->label('Client')
                    ->state(fn (AiEscalation $record): ?string => $record->conversation?->businessEntity?->name),
                TextColumn::make('user_question')
                    ->label('Question')
                    ->limit(50)
                    ->tooltip(fn (AiEscalation $record): ?string => $record->user_question),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('sla_deadline')
                    ->label('SLA deadline')
                    ->dateTime('d.m.Y H:i'),
            ]);
    }

    /**
     * Five most recent escalations for the current firm tenant, or an empty
     * result when no tenant is resolved (default-deny).
     */
    private function escalationsForFirm(): Builder
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return AiEscalation::query()->whereRaw('1 = 0');
        }

        $firmId = $tenant->getKey();

        return AiEscalation::query()
            ->whereHas('conversation.businessEntity.accountantAssignments', fn (Builder $query) => $query
                ->whereNull('revoked_at')
                ->where('accounting_firm_id', $firmId))
            ->with('conversation.businessEntity')
            ->latest('created_at')
            ->limit(5);
    }
}
