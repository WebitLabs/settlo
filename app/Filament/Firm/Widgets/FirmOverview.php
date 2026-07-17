<?php

namespace App\Filament\Firm\Widgets;

use App\Enums\AiEscalationStatus;
use App\Models\AiEscalation;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Headline numbers for the firm dashboard: how much work is waiting, how much of
 * it is close to breaching its SLA, how many clients the firm is managing, and
 * how many escalations were answered this month. Every figure is scoped to the
 * current firm tenant through the same active-assignment boundary the escalation
 * queue enforces, so numbers never leak across firms.
 */
class FirmOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();

        if ($tenant === null) {
            return [];
        }

        $pending = $this->escalationsForFirm()
            ->where('status', AiEscalationStatus::Pending->value)
            ->count();

        $slaThreshold = Carbon::now()->addHours(4);

        $atRisk = $this->escalationsForFirm()
            ->whereNull('answered_at')
            ->where(fn (Builder $query) => $query
                ->where('sla_breached', true)
                ->orWhere('sla_deadline', '<', $slaThreshold))
            ->count();

        $activeClients = $tenant->activeAssignments()
            ->distinct('business_entity_id')
            ->count('business_entity_id');

        $answeredThisMonth = $this->escalationsForFirm()
            ->whereNotNull('answered_at')
            ->whereBetween('answered_at', [
                Carbon::now()->startOfMonth(),
                Carbon::now()->endOfMonth(),
            ])
            ->count();

        return [
            Stat::make('Pending escalations', (string) $pending)
                ->description('Awaiting an accountant')
                ->color($pending > 0 ? 'warning' : 'gray'),
            Stat::make('SLA at risk', (string) $atRisk)
                ->description('Due within 4h or overdue')
                ->color($atRisk > 0 ? 'danger' : 'gray'),
            Stat::make('Active clients', (string) $activeClients)
                ->description('Businesses you manage')
                ->color('primary'),
            Stat::make('Answered this month', (string) $answeredThisMonth)
                ->description(Carbon::now()->format('F'))
                ->color('success'),
        ];
    }

    /**
     * Base query for escalations belonging to the current firm tenant: only
     * those raised by a business the firm is actively (non-revoked) assigned to.
     */
    private function escalationsForFirm(): Builder
    {
        $firmId = Filament::getTenant()->getKey();

        return AiEscalation::query()
            ->whereHas('conversation.businessEntity.accountantAssignments', fn (Builder $query) => $query
                ->whereNull('revoked_at')
                ->where('accounting_firm_id', $firmId));
    }
}
