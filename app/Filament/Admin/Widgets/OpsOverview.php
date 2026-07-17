<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\AiEscalationStatus;
use App\Enums\ExpenseProcessingStatus;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\Expense;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Operational health for the current calendar month.
 *
 * - Escalation rate: escalations raised this month over assistant messages
 *   generated this month (how often the AI has to hand off to a human).
 * - SLA compliance: of escalations answered this month, the share answered
 *   without breaching their SLA deadline.
 * - Open escalations: everything still awaiting or in progress, all-time.
 * - OCR failure rate: of receipts whose extraction finished this month
 *   (failed or extracted), the share that failed.
 *
 * All ratios guard against an empty denominator so a fresh install renders 0%.
 */
class OpsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        $monthStart = Carbon::now()->startOfMonth();

        $assistantMessages = AiMessage::query()
            ->where('role', 'assistant')
            ->where('created_at', '>=', $monthStart)
            ->count();

        $escalationsThisMonth = AiEscalation::query()
            ->where('created_at', '>=', $monthStart)
            ->count();

        $escalationRate = $assistantMessages > 0
            ? round($escalationsThisMonth / $assistantMessages * 100, 1)
            : 0.0;

        $answeredThisMonth = AiEscalation::query()
            ->whereNotNull('answered_at')
            ->where('answered_at', '>=', $monthStart)
            ->count();

        $answeredWithinSla = AiEscalation::query()
            ->whereNotNull('answered_at')
            ->where('answered_at', '>=', $monthStart)
            ->where('sla_breached', false)
            ->count();

        $slaCompliance = $answeredThisMonth > 0
            ? round($answeredWithinSla / $answeredThisMonth * 100, 1)
            : 0.0;

        $openEscalations = AiEscalation::query()
            ->whereIn('status', [
                AiEscalationStatus::Pending->value,
                AiEscalationStatus::InProgress->value,
            ])
            ->count();

        $ocrFailed = Expense::query()
            ->where('created_at', '>=', $monthStart)
            ->where('processing_status', ExpenseProcessingStatus::Failed->value)
            ->count();

        $ocrExtracted = Expense::query()
            ->where('created_at', '>=', $monthStart)
            ->where('processing_status', ExpenseProcessingStatus::Extracted->value)
            ->count();

        $ocrProcessed = $ocrFailed + $ocrExtracted;

        $ocrFailureRate = $ocrProcessed > 0
            ? round($ocrFailed / $ocrProcessed * 100, 1)
            : 0.0;

        return [
            Stat::make('AI escalation rate', $escalationRate.'%')
                ->description($escalationsThisMonth.' of '.$assistantMessages.' answers escalated')
                ->color($escalationRate > 20 ? 'warning' : 'gray'),
            Stat::make('SLA compliance', $slaCompliance.'%')
                ->description($answeredWithinSla.' of '.$answeredThisMonth.' on time')
                ->color($slaCompliance >= 90 || $answeredThisMonth === 0 ? 'success' : 'danger'),
            Stat::make('Open escalations', (string) $openEscalations)
                ->description('Awaiting or in progress')
                ->color($openEscalations > 0 ? 'warning' : 'gray'),
            Stat::make('OCR failure rate', $ocrFailureRate.'%')
                ->description($ocrFailed.' of '.$ocrProcessed.' receipts failed')
                ->color($ocrFailureRate > 10 ? 'danger' : 'gray'),
        ];
    }
}
