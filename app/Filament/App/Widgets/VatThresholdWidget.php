<?php

namespace App\Filament\App\Widgets;

use App\Models\BusinessEntity;
use App\Models\TaxEstimation;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * Progress bar toward the CHF 100'000 VAT registration threshold, coloured by
 * the alert band from the latest estimation and annotated with the projected
 * crossing date when one is available.
 */
class VatThresholdWidget extends Widget
{
    protected string $view = 'filament.app.widgets.vat-threshold';

    protected static ?int $sort = 4;

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

        $pct = (float) ($estimation?->vat_threshold_pct ?? 0);

        return [
            'hasData' => $estimation !== null,
            'level' => $estimation?->vat_alert_level ?? 'none',
            'progressPct' => $pct,
            'barPct' => min(100, max(0, $pct)),
            'barColor' => $this->barColor($estimation?->vat_alert_level),
            'crossingDate' => $this->crossingLabel($estimation),
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
