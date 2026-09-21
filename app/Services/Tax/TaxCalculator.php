<?php

namespace App\Services\Tax;

use App\Models\CantonFiscalConfig;
use App\Models\SocialInsuranceRate;

/**
 * Canton-aware Swiss tax calculator for self-employed sole proprietors.
 * Implements the 10-step algorithm from Settlo Tax Engine Algorithms v2.0.
 * All arithmetic uses BCMath at scale 6; only the final result is rounded to
 * CHF 0.01. Never round intermediate values.
 */
class TaxCalculator
{
    private const SCALE = 6;

    public function __construct(private readonly RateRepository $rates) {}

    public function calculate(TaxInput $input): TaxResult
    {
        // B-permit holders fall under the Quellensteuer regime — stop.
        if ($input->residencePermit->triggersQuellensteuer()) {
            return TaxResult::quellensteuer();
        }

        $canton = $this->rates->cantonConfig($input->cantonCode, $input->fiscalYear);
        $si = $this->rates->socialInsuranceRate($input->fiscalYear);
        $multiplier = $input->communeMultiplier ?? (float) $canton->communal_multiplier_default;

        $core = $this->computeCore(
            (string) $input->grossRevenue,
            (string) $input->deductibleExpenses,
            $input,
            $canton,
            $si,
            (string) $multiplier,
        );

        // Step 10 — annualisation for the full-year projection.
        $projectedRevenue = (string) $input->grossRevenue;
        $projectedTotalTax = $core['totalTax'];

        if ($input->daysElapsed !== null && $input->daysElapsed > 0) {
            $days = max(1, $input->daysElapsed);
            $annualRevenue = bcdiv(bcmul((string) $input->grossRevenue, '365', self::SCALE), (string) $days, self::SCALE);
            $annualExpenses = bcdiv(bcmul((string) $input->deductibleExpenses, '365', self::SCALE), (string) $days, self::SCALE);
            $projected = $this->computeCore($annualRevenue, $annualExpenses, $input, $canton, $si, (string) $multiplier);
            $projectedRevenue = $annualRevenue;
            $projectedTotalTax = $projected['totalTax'];
        }

        return new TaxResult(
            quellensteuerRegime: false,
            grossRevenue: $this->round($input->grossRevenue),
            totalExpenses: $this->round($input->deductibleExpenses),
            netIncome: $this->round($core['net']),
            ahvContribution: $this->round($core['ahv']),
            ivContribution: $this->round($core['iv']),
            eoContribution: $this->round($core['eo']),
            totalSocialInsurance: $this->round($core['totalSI']),
            ahvDeduction: $this->round($core['ahvDeduction']),
            pillar3aDeduction: $this->round($core['pillar3a']),
            childDeduction: $this->round($core['childDeduction']),
            minimumContributionApplied: $core['minimumApplied'],
            taxableIncome: $this->round($core['taxable']),
            federalTax: $this->round($core['federalTax']),
            cantonalTax: $this->round($core['cantonalSimple']),
            communalTax: $this->round($core['communalTax']),
            churchTax: $this->round($core['churchTax']),
            totalIncomeTax: $this->round($core['totalIncomeTax']),
            totalTaxBurden: $this->round($core['totalTax']),
            monthlyReserve: $this->round(bcdiv($core['totalTax'], '12', self::SCALE)),
            effectiveRate: $this->roundRate($core['effectiveRate']),
            projectedAnnualRevenue: $this->round($projectedRevenue),
            projectedTotalTax: $this->round($projectedTotalTax),
            projectedMonthlyReserve: $this->round(bcdiv($projectedTotalTax, '12', self::SCALE)),
            lossYear: $core['lossYear'],
            ageExemptionApplied: $core['ageExemption'],
            ratesSnapshot: [
                'canton_code' => $input->cantonCode,
                'fiscal_year' => $input->fiscalYear,
                'cantonal_rate' => (float) $canton->cantonal_rate,
                'communal_multiplier' => (float) $multiplier,
                'church_rate' => (float) $canton->church_rate,
                'child_deduction' => (int) $canton->child_deduction,
                'ahv_rate' => (float) $si->ahv_rate,
                'iv_rate' => (float) $si->iv_rate,
                'eo_rate' => (float) $si->eo_rate,
                'pillar3a_max' => $this->round($core['pillar3aCap']),
                'ahv_minimum' => (float) $si->ahv_minimum,
                'age_exemption_amount' => (float) $si->age_exemption_amount,
                'deductions' => [
                    'ahv' => $this->round($core['ahvDeduction']),
                    'pillar3a' => $this->round($core['pillar3a']),
                    'children' => $this->round($core['childDeduction']),
                    'other_income' => $this->round((string) $input->otherIncome),
                    'minimum_contribution_applied' => $core['minimumApplied'],
                    'loss_year' => $core['lossYear'],
                    'age_exemption_applied' => $core['ageExemption'],
                ],
            ],
        );
    }

    /**
     * Steps 1–9 on a given revenue/expense pair. Returns unrounded string values.
     *
     * @return array<string, mixed>
     */
    private function computeCore(
        string $grossRevenue,
        string $deductibleExpenses,
        TaxInput $input,
        CantonFiscalConfig $canton,
        SocialInsuranceRate $si,
        string $multiplier,
    ): array {
        // Step 1 — net business income (may be negative in a loss year).
        $net = bcsub($grossRevenue, $deductibleExpenses, self::SCALE);
        $netForAhv = bccomp($net, '0', self::SCALE) < 0 ? '0' : $net;

        // Age 65+ exemption on the first CHF 16,800 of AHV base.
        $ageExemption = false;
        $ahvBase = $netForAhv;
        if ($input->age !== null && $input->age >= 65) {
            $ahvBase = bcsub($netForAhv, (string) $si->age_exemption_amount, self::SCALE);
            if (bccomp($ahvBase, '0', self::SCALE) < 0) {
                $ahvBase = '0';
            }
            $ageExemption = true;
        }

        // Step 2 — social insurance (AHV/IV/EO) on the AHV base.
        $ahv = $this->pct($ahvBase, (string) $si->ahv_rate);
        $iv = $this->pct($ahvBase, (string) $si->iv_rate);
        $eo = $this->pct($ahvBase, (string) $si->eo_rate);
        $totalSI = bcadd(bcadd($ahv, $iv, self::SCALE), $eo, self::SCALE);

        // Every self-employed person owes at least the annual minimum contribution
        // (CHF 514) — also at zero revenue and in a loss year — unless their whole
        // AHV base is covered by the age-65 exemption. The minimum is apportioned
        // across AHV/IV/EO by rate weight so the parts always add up to the total
        // that is charged.
        $fullyAgeExempt = $ageExemption && bccomp($ahvBase, '0', self::SCALE) <= 0;
        $minimumApplied = false;

        if (! $fullyAgeExempt
            && bccomp($totalSI, (string) $si->ahv_minimum, self::SCALE) < 0) {
            $rateSum = bcadd(bcadd((string) $si->ahv_rate, (string) $si->iv_rate, self::SCALE), (string) $si->eo_rate, self::SCALE);
            $ahv = bcdiv(bcmul((string) $si->ahv_minimum, (string) $si->ahv_rate, self::SCALE), $rateSum, self::SCALE);
            $iv = bcdiv(bcmul((string) $si->ahv_minimum, (string) $si->iv_rate, self::SCALE), $rateSum, self::SCALE);
            $eo = bcsub(bcsub((string) $si->ahv_minimum, $ahv, self::SCALE), $iv, self::SCALE);
            $totalSI = (string) $si->ahv_minimum;
            $minimumApplied = true;
        }

        // Only 50% of the AHV actually charged (not IV/EO) is deductible from taxable income.
        $ahvDeduction = bcmul($ahv, '0.5', self::SCALE);

        // Step 3 — taxable income.
        $pillar3aCap = $this->pillar3aCap($input->hasPillar2, $netForAhv, $si);
        $pillar3a = bccomp((string) $input->pillar3aAmount, $pillar3aCap, self::SCALE) > 0
            ? $pillar3aCap
            : (string) $input->pillar3aAmount;
        $childDeduction = bcmul((string) $input->numberOfChildren, (string) $canton->child_deduction, self::SCALE);

        $taxable = bcadd(
            bcsub(bcsub(bcsub($net, $ahvDeduction, self::SCALE), $pillar3a, self::SCALE), $childDeduction, self::SCALE),
            (string) $input->otherIncome,
            self::SCALE,
        );
        $taxableForTax = bccomp($taxable, '0', self::SCALE) < 0 ? '0' : $taxable;

        // Step 4 — federal direct tax (progressive brackets).
        $federalTax = $this->federalTax($taxableForTax, $input->maritalStatus->tariff(), $input->fiscalYear);

        // Step 5 — cantonal simple tax (Einfache Steuer).
        $cantonalSimple = $this->pct($taxableForTax, (string) $canton->cantonal_rate);

        // Step 6 — communal tax = cantonal simple × multiplier.
        $communalTax = $this->pct($cantonalSimple, $multiplier);

        // Step 7 — church tax (% of cantonal simple), if a church member.
        $churchTax = $input->kirchensteuer ? $this->pct($cantonalSimple, (string) $canton->church_rate) : '0';

        // Step 8–9 — totals.
        $totalIncomeTax = bcadd(bcadd(bcadd($federalTax, $cantonalSimple, self::SCALE), $communalTax, self::SCALE), $churchTax, self::SCALE);
        $totalTax = bcadd($totalIncomeTax, $totalSI, self::SCALE);

        $effectiveRate = bccomp($grossRevenue, '0', self::SCALE) > 0
            ? bcmul(bcdiv($totalTax, $grossRevenue, self::SCALE), '100', self::SCALE)
            : '0';

        $lossYear = bccomp($net, '0', self::SCALE) < 0;

        return compact(
            'net', 'ahv', 'iv', 'eo', 'totalSI', 'ahvDeduction', 'pillar3a', 'pillar3aCap', 'childDeduction', 'minimumApplied', 'taxable',
            'federalTax', 'cantonalSimple', 'communalTax', 'churchTax',
            'totalIncomeTax', 'totalTax', 'effectiveRate', 'ageExemption', 'lossYear',
        );
    }

    /**
     * Deductible Pillar 3a maximum. With a pension fund (Pillar 2) the small
     * cap applies. Without one, the self-employed may deduct up to 20 % of
     * their net earned income, but no more than the large cap.
     */
    private function pillar3aCap(bool $hasPillar2, string $netEarnedIncome, SocialInsuranceRate $si): string
    {
        if ($hasPillar2) {
            return (string) $si->pillar3a_max_with_p2;
        }

        $incomeCap = $this->pct($netEarnedIncome, RateRepository::PILLAR_3A_SELF_EMPLOYED_INCOME_PERCENT);

        return bccomp($incomeCap, (string) $si->pillar3a_max_se, self::SCALE) < 0
            ? $incomeCap
            : (string) $si->pillar3a_max_se;
    }

    private function federalTax(string $income, string $tariff, int $year): string
    {
        $brackets = $this->rates->federalBrackets($tariff, $year);

        foreach ($brackets as $bracket) {
            $from = (string) $bracket->bracket_from;
            $to = $bracket->bracket_to;

            $aboveFrom = bccomp($income, $from, self::SCALE) >= 0;
            $belowTo = $to === null || bccomp($income, (string) $to, self::SCALE) < 0;

            if ($aboveFrom && $belowTo) {
                $excess = bcsub($income, $from, self::SCALE);

                return bcadd((string) $bracket->base_amount, $this->pct($excess, (string) $bracket->rate), self::SCALE);
            }
        }

        return '0';
    }

    /** value × rate / 100 */
    private function pct(string $value, string $rate): string
    {
        return bcdiv(bcmul($value, $rate, self::SCALE), '100', self::SCALE);
    }

    private function round(string|float $value): float
    {
        return round((float) $value, 2);
    }

    private function roundRate(string|float $value): float
    {
        return round((float) $value, 1);
    }
}
