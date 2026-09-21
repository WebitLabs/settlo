<?php

namespace App\Services\Tax;

use Illuminate\Support\Carbon;

/**
 * VAT registration threshold tracking. Independent of income tax; recomputed on
 * every invoice save. Implements the alert ladder and crossing-date projection
 * from Settlo Tax Engine Algorithms v2.0.
 *
 * For sole proprietorships the threshold is legally per person, so the caller
 * passes the owner's consolidated revenue and their largest single invoice
 * across every sole proprietorship — not one workspace's figures.
 */
class VatThresholdService
{
    public function __construct(private readonly RateRepository $rates) {}

    /**
     * @return array{
     *     level: string,
     *     progress_pct: float,
     *     crossing_date: ?string,
     *     threshold: int,
     *     revenue_ytd: float,
     *     days_to_threshold: ?int,
     *     registration_window_days: int,
     *     single_invoice: bool,
     * }
     */
    public function evaluate(
        float $revenueYtd,
        int $daysElapsed,
        int $fiscalYear,
        ?float $largestSingleInvoice = null,
    ): array {
        $config = $this->rates->vatConfig($fiscalYear);
        $threshold = (int) $config->registration_threshold;
        $windowDays = (int) ($config->registration_window_days ?: 30);

        // Bands are compared on the exact progress; the rounded value is only
        // ever displayed, so 74.99 % never reads as the 75 % warning band.
        $exactProgress = $threshold > 0 ? $revenueYtd / $threshold * 100 : 0.0;
        $progress = round($exactProgress, 1);

        // A single invoice >= the threshold triggers mandatory registration
        // immediately, regardless of YTD total (MWSTG Art. 10).
        if ($largestSingleInvoice !== null && $largestSingleInvoice >= $threshold) {
            return [
                'level' => 'mandatory',
                'progress_pct' => $progress,
                'crossing_date' => null,
                'threshold' => $threshold,
                'revenue_ytd' => round($revenueYtd, 2),
                'days_to_threshold' => null,
                'registration_window_days' => $windowDays,
                'single_invoice' => true,
            ];
        }

        $daysToThreshold = $this->daysToThreshold($revenueYtd, $daysElapsed, $threshold);

        return [
            'level' => $this->level($exactProgress),
            'progress_pct' => $progress,
            'crossing_date' => $daysToThreshold === null ? null : Carbon::now()->addDays($daysToThreshold)->toDateString(),
            'threshold' => $threshold,
            'revenue_ytd' => round($revenueYtd, 2),
            'days_to_threshold' => $daysToThreshold,
            'registration_window_days' => $windowDays,
            'single_invoice' => false,
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

    /**
     * Whole days until the average daily revenue so far reaches the threshold,
     * or null when the run rate never gets there (no revenue, or already over).
     */
    private function daysToThreshold(float $revenueYtd, int $daysElapsed, int $threshold): ?int
    {
        if ($revenueYtd <= 0 || $daysElapsed <= 0 || $threshold <= 0 || $revenueYtd >= $threshold) {
            return null;
        }

        $dailyRate = $revenueYtd / $daysElapsed;

        if ($dailyRate <= 0) {
            return null;
        }

        return (int) ceil(($threshold - $revenueYtd) / $dailyRate);
    }
}
