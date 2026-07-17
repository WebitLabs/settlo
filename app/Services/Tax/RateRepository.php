<?php

namespace App\Services\Tax;

use App\Models\CantonFiscalConfig;
use App\Models\FederalTaxBracket;
use App\Models\SocialInsuranceRate;
use App\Models\VatConfig;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Loads effective-dated fiscal rates from the database for a given year. All
 * rates are stored in the DB (never hardcoded) so the annual October update is
 * a data change, not a code change.
 */
class RateRepository
{
    public function cantonConfig(string $cantonCode, int $year): CantonFiscalConfig
    {
        $config = CantonFiscalConfig::query()
            ->whereHas('canton', fn ($q) => $q->where('code', $cantonCode))
            ->where('year', $year)
            ->first();

        if (! $config) {
            throw new RuntimeException("No fiscal config for canton {$cantonCode} in {$year}.");
        }

        return $config;
    }

    public function socialInsuranceRate(int $year): SocialInsuranceRate
    {
        $rate = SocialInsuranceRate::where('year', $year)->first();

        if (! $rate) {
            throw new RuntimeException("No social-insurance rates for {$year}.");
        }

        return $rate;
    }

    public function vatConfig(int $year): VatConfig
    {
        $config = VatConfig::where('year', $year)->first();

        if (! $config) {
            throw new RuntimeException("No VAT config for {$year}.");
        }

        return $config;
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
}
