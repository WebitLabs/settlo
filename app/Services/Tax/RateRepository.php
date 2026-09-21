<?php

namespace App\Services\Tax;

use App\Models\CantonFiscalConfig;
use App\Models\FederalTaxBracket;
use App\Models\SocialInsuranceRate;
use App\Models\VatConfig;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Loads effective-dated fiscal rates from the database for a given year. All
 * rates are stored in the DB (never hardcoded) so the annual October update is
 * a data change, not a code change.
 *
 * Every lookup resolves the row that is in force on a reference date inside the
 * requested fiscal year (today when the year is the current one, 31 December
 * otherwise), so a mid-year rate change takes effect on its effective_from and
 * a superseded row (effective_to in the past) is never used.
 */
class RateRepository
{
    /**
     * Without a pension fund, the self-employed may deduct Pillar 3a
     * contributions of up to this percentage of their net earned income
     * (capped by pillar3a_max_se).
     */
    public const string PILLAR_3A_SELF_EMPLOYED_INCOME_PERCENT = '20';

    public function cantonConfig(string $cantonCode, int $year, CarbonInterface|string|null $on = null): CantonFiscalConfig
    {
        $config = $this->effective(
            fn (): Builder => CantonFiscalConfig::query()->whereHas('canton', fn (Builder $q) => $q->where('code', $cantonCode)),
            $year,
            $on,
        );

        if (! $config instanceof CantonFiscalConfig) {
            throw new RuntimeException("No fiscal config for canton {$cantonCode} in {$year}.");
        }

        return $config;
    }

    public function socialInsuranceRate(int $year, CarbonInterface|string|null $on = null): SocialInsuranceRate
    {
        $rate = $this->effective(fn (): Builder => SocialInsuranceRate::query(), $year, $on);

        if (! $rate instanceof SocialInsuranceRate) {
            throw new RuntimeException("No social-insurance rates for {$year}.");
        }

        return $rate;
    }

    /**
     * Legal maximum for deductible Pillar 3a contributions in a year: the small
     * cap with a pension fund (Pillar 2), the large one without. Without a
     * pension fund the calculator further limits the deduction to 20 % of net
     * earned income, which forms cannot know in advance.
     */
    public function pillar3aCap(bool $hasPillar2, int $year): int
    {
        $rate = $this->socialInsuranceRate($year);

        return (int) ($hasPillar2 ? $rate->pillar3a_max_with_p2 : $rate->pillar3a_max_se);
    }

    public function vatConfig(int $year, CarbonInterface|string|null $on = null): VatConfig
    {
        $config = $this->effective(fn (): Builder => VatConfig::query(), $year, $on);

        if (! $config instanceof VatConfig) {
            throw new RuntimeException("No VAT config for {$year}.");
        }

        return $config;
    }

    /**
     * The VAT rates in force in a fiscal year, as select options keyed by the
     * compact rate string ("8.1"). Forms must build their VAT rate choices from
     * this rather than hardcoding the rates.
     *
     * @return array<string, string>
     */
    public function vatRateOptions(int $year, CarbonInterface|string|null $on = null): array
    {
        $config = $this->vatConfig($year, $on);

        $options = [];

        foreach ([
            [$config->standard_rate, 'standard'],
            [$config->reduced_rate, 'reduced'],
            [$config->special_rate, 'accommodation'],
        ] as [$rate, $label]) {
            $key = self::rateKey($rate);

            if ($key === '0') {
                continue;
            }

            $options[$key] = "{$key}% ({$label})";
        }

        $options['0'] = '0% (exempt / export)';

        return $options;
    }

    /**
     * The standard VAT rate in force in a fiscal year as a compact string
     * ("8.1"), for use as a form default.
     */
    public function standardVatRate(int $year, CarbonInterface|string|null $on = null): string
    {
        return self::rateKey($this->vatConfig($year, $on)->standard_rate);
    }

    /**
     * @return Collection<int, FederalTaxBracket>
     */
    public function federalBrackets(string $tariff, int $year)
    {
        return FederalTaxBracket::query()
            ->where('year', $year)
            ->where('tariff', $tariff)
            ->orderBy('bracket_from')
            ->get();
    }

    /**
     * The reference date a lookup for $year resolves on: today when the
     * requested year is the current one, otherwise the last day of that year.
     */
    public function referenceDate(int $year, CarbonInterface|string|null $on = null): CarbonImmutable
    {
        if ($on !== null) {
            return CarbonImmutable::parse($on)->startOfDay();
        }

        $today = CarbonImmutable::today();

        return match (true) {
            $today->year === $year => $today,
            $today->year > $year => CarbonImmutable::create($year, 12, 31)->startOfDay(),
            default => CarbonImmutable::create($year, 1, 1)->startOfDay(),
        };
    }

    /**
     * Resolve the rate row for a fiscal year, honouring effective dating:
     *
     * 1. the row for that year whose effective window contains the reference date;
     * 2. otherwise the most recent row of any year that is in force on it — a
     *    future year's rates, seeded ahead of time, are never applied early;
     * 3. otherwise the row for that year, whatever its window (data without
     *    usable effective dating).
     *
     * @param  callable(): Builder<covariant \Illuminate\Database\Eloquent\Model>  $base
     */
    private function effective(callable $base, int $year, CarbonInterface|string|null $on): ?object
    {
        $reference = $this->referenceDate($year, $on)->toDateString();

        $inForce = fn (Builder $query): Builder => $query
            ->where(function (Builder $q) use ($reference): void {
                $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $reference);
            })
            ->where(function (Builder $q) use ($reference): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $reference);
            });

        return $inForce($base()->where('year', $year))->orderByDesc('effective_from')->first()
            ?? $inForce($base())->orderByDesc('effective_from')->first()
            ?? $base()->where('year', $year)->orderByDesc('effective_from')->first();
    }

    /**
     * "8.100" → "8.1", "0.000" → "0".
     */
    private static function rateKey(float|string|null $rate): string
    {
        $key = rtrim(rtrim(number_format((float) $rate, 3, '.', ''), '0'), '.');

        return $key === '' ? '0' : $key;
    }
}
