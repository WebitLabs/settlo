<?php

namespace App\Services\Tax;

use Illuminate\Support\Carbon;

/**
 * The user-facing wording of the VAT threshold ladder, in one place so the
 * notification, the dashboard widget and the to-do item escalate in step.
 * Messages follow Settlo Tax Engine Algorithms v2.0 section 5.2; the mandatory
 * band names the legal registration window from vat_configs.
 *
 * @phpstan-type VatEvaluation array{
 *     level: string,
 *     progress_pct: float,
 *     crossing_date: ?string,
 *     threshold: int,
 *     days_to_threshold?: ?int,
 *     registration_window_days?: int,
 *     single_invoice?: bool,
 * }
 */
final class VatAlertCopy
{
    /**
     * Alert bands ordered by escalation severity.
     *
     * @var array<string, int>
     */
    public const array RANK = [
        'none' => 0,
        'info' => 1,
        'warning' => 2,
        'critical' => 3,
        'mandatory' => 4,
    ];

    public static function rank(?string $level): int
    {
        return self::RANK[$level ?? 'none'] ?? 0;
    }

    /**
     * Whether the band is actionable enough to interrupt the owner.
     */
    public static function isActionable(?string $level): bool
    {
        return self::rank($level) >= self::RANK['warning'];
    }

    /**
     * Short status label for a badge or a progress card.
     */
    public static function status(?string $level): string
    {
        return match ($level) {
            'mandatory' => 'Registration mandatory',
            'critical' => 'Register for VAT now',
            'warning' => 'Consider VAT registration',
            'info' => 'Approaching the threshold',
            default => 'Well below the threshold',
        };
    }

    /**
     * Notification / to-do headline for a band.
     */
    public static function title(?string $level): string
    {
        return match ($level) {
            'mandatory' => 'VAT registration is mandatory',
            'critical' => 'Register for VAT now',
            'warning' => 'Consider VAT registration',
            'info' => 'You are approaching the VAT threshold',
            default => 'VAT threshold',
        };
    }

    /**
     * Filament colour for a band.
     */
    public static function color(?string $level): string
    {
        return match ($level) {
            'mandatory' => 'danger',
            'critical' => 'warning',
            'warning' => 'warning',
            'info' => 'info',
            default => 'gray',
        };
    }

    /**
     * The escalating message for a band. $vat is a VatThresholdService
     * evaluation; missing keys degrade to the shortest sensible wording.
     *
     * @param  VatEvaluation  $vat
     */
    public static function body(array $vat): string
    {
        $threshold = self::money($vat['threshold'] ?? 100000);
        $pct = self::percent((float) ($vat['progress_pct'] ?? 0));
        $days = $vat['days_to_threshold'] ?? null;
        $date = self::date($vat['crossing_date'] ?? null);
        $window = (int) ($vat['registration_window_days'] ?? 30);

        return match ($vat['level'] ?? 'none') {
            'mandatory' => ($vat['single_invoice'] ?? false)
                ? "A single invoice of CHF {$threshold} or more crosses the threshold on its own. VAT registration was mandatory within {$window} days of that invoice."
                : "You have crossed CHF {$threshold}. VAT registration was mandatory within {$window} days of crossing.",
            'critical' => $date !== null
                ? "Register for VAT now — at {$pct}% you will likely cross CHF {$threshold} by {$date}."
                : "Register for VAT now — you are at {$pct}% of the CHF {$threshold} threshold.",
            'warning' => $days !== null
                ? "Consider VAT registration. At {$pct}% you may cross CHF {$threshold} in {$days} days."
                : "Consider VAT registration. You are at {$pct}% of the CHF {$threshold} threshold.",
            'info' => $date !== null
                ? "You are at {$pct}% of the CHF {$threshold} VAT threshold. Estimated crossing: {$date}."
                : "You are at {$pct}% of the CHF {$threshold} VAT threshold.",
            default => "You are at {$pct}% of the CHF {$threshold} VAT threshold.",
        };
    }

    /**
     * The to-do item label, which has to stand on its own in a list.
     *
     * @param  VatEvaluation  $vat
     */
    public static function todo(array $vat): string
    {
        $threshold = self::money($vat['threshold'] ?? 100000);
        $window = (int) ($vat['registration_window_days'] ?? 30);
        $pct = self::percent((float) ($vat['progress_pct'] ?? 0));

        return match ($vat['level'] ?? 'none') {
            'mandatory' => "Register for VAT — you crossed CHF {$threshold}, registration was mandatory within {$window} days",
            'critical' => "Register for VAT now — you are at {$pct}% of CHF {$threshold}",
            'warning' => "Consider VAT registration — you are at {$pct}% of CHF {$threshold}",
            default => 'Consider VAT registration',
        };
    }

    /**
     * "100'000" — Swiss thousands separator, no decimals.
     */
    public static function money(float|int|string|null $value): string
    {
        return number_format((float) $value, 0, '.', "'");
    }

    /**
     * "68.4" / "100" — one decimal, trailing zero dropped.
     */
    private static function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }

    private static function date(?string $date): ?string
    {
        return $date === null ? null : Carbon::parse($date)->translatedFormat('F Y');
    }
}
