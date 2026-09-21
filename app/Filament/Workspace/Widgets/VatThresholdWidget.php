<?php

namespace App\Filament\Workspace\Widgets;

use App\Models\BusinessEntity;
use App\Models\TaxEstimation;
use App\Services\Tax\VatAlertCopy;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Progress bar toward the VAT registration threshold, coloured by the alert
 * band from the latest estimation and annotated with the escalating message for
 * that band. For a sole proprietorship the bar tracks the owner's consolidated
 * revenue — the threshold is per person — with this workspace's own
 * contribution shown underneath.
 */
class VatThresholdWidget extends Widget
{
    protected string $view = 'filament.workspace.widgets.vat-threshold';

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 1;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $entity = Filament::getTenant();
        $estimation = $entity instanceof BusinessEntity
            ? $entity->latestTaxEstimation($this->fiscalYear())
            : null;

        $vat = $this->vatOf($estimation);
        $pct = (float) ($vat['progress_pct'] ?? 0);
        $level = (string) ($vat['level'] ?? 'none');

        return [
            'hasData' => $estimation !== null,
            'level' => $level,
            'status' => VatAlertCopy::status($level),
            'message' => $estimation !== null ? VatAlertCopy::body($vat) : null,
            'progressPct' => $pct,
            'barPct' => min(100, max(0, $pct)),
            'barColor' => $this->barColor($level),
            'threshold' => VatAlertCopy::money($vat['threshold'] ?? 100000),
            'crossingDate' => $this->crossingLabel($estimation),
            'isOwnerLevel' => ($vat['scope'] ?? null) === 'owner' && array_key_exists('own_pct', $vat),
            'ownPct' => (float) ($vat['own_pct'] ?? $pct),
            'ownRevenue' => VatAlertCopy::money($vat['own_revenue'] ?? 0),
        ];
    }

    /**
     * The stored VAT evaluation of an estimation, falling back to the columns
     * for rows written before the evaluation was snapshotted.
     *
     * @return array<string, mixed>
     */
    private function vatOf(?TaxEstimation $estimation): array
    {
        if ($estimation === null) {
            return ['level' => 'none', 'progress_pct' => 0.0, 'threshold' => 100000];
        }

        $vat = $estimation->inputs['vat'] ?? null;

        if (is_array($vat)) {
            return $vat;
        }

        return [
            'level' => $estimation->vat_alert_level ?? 'none',
            'progress_pct' => (float) $estimation->vat_threshold_pct,
            'crossing_date' => $estimation->vat_crossing_date?->toDateString(),
            'threshold' => 100000,
        ];
    }

    private function fiscalYear(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }

    /**
     * Green / yellow / orange / red ladder mirroring the alert bands in
     * VatThresholdService (none|info => green, warning => yellow,
     * critical => orange, mandatory => red).
     */
    private function barColor(?string $level): string
    {
        return match ($level) {
            'mandatory' => 'bg-red-500',
            'critical' => 'bg-orange-500',
            'warning' => 'bg-amber-400',
            default => 'bg-primary-500',
        };
    }

    private function crossingLabel(?TaxEstimation $estimation): ?string
    {
        if ($estimation?->vat_crossing_date === null) {
            return null;
        }

        return Carbon::parse($estimation->vat_crossing_date)->translatedFormat('F Y');
    }
}
