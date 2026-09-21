<?php

namespace App\Services\Reporting;

use Illuminate\Support\Carbon;

/**
 * A Swiss VAT reporting period: a quarter (the default for the effective
 * method), a half-year (net tax rate method) or a whole year. Holds the
 * half-open date range [start, end) used by every query, so no whereYear() is
 * needed and the date indexes stay usable.
 */
final readonly class VatReturnPeriod
{
    public function __construct(
        public int $year,
        public string $key,
        public string $label,
        public string $start,
        public string $end,
    ) {}

    /**
     * The selectable periods of a year, keyed by their form value.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'q1' => 'Q1 (Jan–Mar)',
            'q2' => 'Q2 (Apr–Jun)',
            'q3' => 'Q3 (Jul–Sep)',
            'q4' => 'Q4 (Oct–Dec)',
            's1' => '1st half-year (Jan–Jun)',
            's2' => '2nd half-year (Jul–Dec)',
            'year' => 'Full year',
        ];
    }

    /**
     * Build a period; an unknown key falls back to the full year.
     */
    public static function make(int $year, string $key): self
    {
        [$firstMonth, $months] = match ($key) {
            'q1' => [1, 3],
            'q2' => [4, 3],
            'q3' => [7, 3],
            'q4' => [10, 3],
            's1' => [1, 6],
            's2' => [7, 6],
            default => [1, 12],
        };

        $key = array_key_exists($key, self::options()) ? $key : 'year';
        $start = Carbon::create($year, $firstMonth, 1);

        return new self(
            year: $year,
            key: $key,
            label: self::options()[$key].' '.$year,
            start: $start->toDateString(),
            end: $start->copy()->addMonths($months)->toDateString(),
        );
    }

    /**
     * The period that contains the given date, on a quarterly cadence.
     */
    public static function currentQuarter(?Carbon $on = null): self
    {
        $on ??= Carbon::today();

        return self::make($on->year, 'q'.$on->quarter);
    }
}
