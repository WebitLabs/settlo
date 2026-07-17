<?php

namespace App\Services\Tax;

use Illuminate\Support\Carbon;

/**
 * VAT registration threshold tracking. Independent of income tax; recomputed on
 * every invoice save. Implements the alert ladder and crossing-date projection
 * from Settlo Tax Engine Algorithms v2.0.
 */
class VatThresholdService
{
    public function __construct(private readonly RateRepository $rates) {}

    /**
     * @return array{level: string, progress_pct: float, crossing_date: ?string, threshold: int}
     */
    public function evaluate(
        float $revenueYtd,
        int $daysElapsed,
        int $fiscalYear,
        ?float $largestSingleInvoice = null,
    ): array {
        $threshold = (int) $this->rates->vatConfig($fiscalYear)->registration_threshold;

        // A single invoice >= the threshold triggers mandatory registration
        // immediately, regardless of YTD total (MWSTG Art. 10).
        if ($largestSingleInvoice !== null && $largestSingleInvoice >= $threshold) {
            return [
                'level' => 'mandatory',
                'progress_pct' => $threshold > 0 ? round($revenueYtd / $threshold * 100, 1) : 0.0,
                'crossing_date' => null,
                'threshold' => $threshold,
            ];
        }

        $progress = $threshold > 0 ? $revenueYtd / $threshold * 100 : 0.0;

        return [
            'level' => $this->level($progress),
            'progress_pct' => round($progress, 1),
            'crossing_date' => $this->crossingDate($revenueYtd, $daysElapsed, $threshold),
            'threshold' => $threshold,
        ];
    }

    private function level(float $progress): string
    {
        return match (true) {
            $progress >= 100 => 'mandatory',
            $progress >= 90 => 'critical',
            $progress >= 75 => 'warning',
            $progress >= 60 => 'info',
            default => 'none',
        };
    }

    private function crossingDate(float $revenueYtd, int $daysElapsed, int $threshold): ?string
    {
        if ($revenueYtd <= 0 || $daysElapsed <= 0 || $revenueYtd >= $threshold) {
            return null;
        }

        $dailyRate = $revenueYtd / $daysElapsed;
        if ($dailyRate <= 0) {
            return null;
        }

        $daysToThreshold = ($threshold - $revenueYtd) / $dailyRate;

        return Carbon::now()->addDays((int) ceil($daysToThreshold))->toDateString();
    }
}
