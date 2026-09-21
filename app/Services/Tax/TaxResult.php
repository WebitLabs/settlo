<?php

namespace App\Services\Tax;

/**
 * Result of a tax calculation, all monetary values rounded to 2 decimals (CHF).
 * A result is a snapshot: it carries the rates it was computed with so it can
 * be persisted and never recalculated from current rates.
 */
final readonly class TaxResult
{
    public function __construct(
        public bool $quellensteuerRegime,
        public float $grossRevenue,
        public float $totalExpenses,
        public float $netIncome,
        public float $ahvContribution,
        public float $ivContribution,
        public float $eoContribution,
        public float $totalSocialInsurance,
        public float $ahvDeduction,
        public float $taxableIncome,
        public float $federalTax,
        public float $cantonalTax,
        public float $communalTax,
        public float $churchTax,
        public float $totalIncomeTax,
        public float $totalTaxBurden,
        public float $monthlyReserve,
        public float $effectiveRate,
        public float $projectedAnnualRevenue,
        public float $projectedTotalTax,
        public float $projectedMonthlyReserve,
        public bool $lossYear,
        public bool $ageExemptionApplied,
        /** @var array<string, mixed> */
        public array $ratesSnapshot,
        public float $pillar3aDeduction = 0,
        public float $childDeduction = 0,
        public bool $minimumContributionApplied = false,
    ) {}

    public static function quellensteuer(): self
    {
        return new self(
            quellensteuerRegime: true,
            grossRevenue: 0, totalExpenses: 0, netIncome: 0,
            ahvContribution: 0, ivContribution: 0, eoContribution: 0,
            totalSocialInsurance: 0, ahvDeduction: 0, taxableIncome: 0,
            federalTax: 0, cantonalTax: 0, communalTax: 0, churchTax: 0,
            totalIncomeTax: 0, totalTaxBurden: 0, monthlyReserve: 0, effectiveRate: 0,
            projectedAnnualRevenue: 0, projectedTotalTax: 0, projectedMonthlyReserve: 0,
            lossYear: false, ageExemptionApplied: false, ratesSnapshot: [],
        );
    }

    /**
     * This result with every contribution, deduction and tax amount multiplied
     * by $share (a decimal fraction, e.g. "0.6"). Used to attribute a business'
     * proportional part of the owner's consolidated personal estimate. Revenue
     * figures and the effective rate are left unchanged.
     */
    public function scaled(string $share): self
    {
        $scale = fn (float $value): float => self::scaleAmount($value, $share);

        return new self(
            quellensteuerRegime: $this->quellensteuerRegime,
            grossRevenue: $this->grossRevenue,
            totalExpenses: $this->totalExpenses,
            netIncome: $this->netIncome,
            ahvContribution: $scale($this->ahvContribution),
            ivContribution: $scale($this->ivContribution),
            eoContribution: $scale($this->eoContribution),
            totalSocialInsurance: $scale($this->totalSocialInsurance),
            ahvDeduction: $scale($this->ahvDeduction),
            taxableIncome: $scale($this->taxableIncome),
            federalTax: $scale($this->federalTax),
            cantonalTax: $scale($this->cantonalTax),
            communalTax: $scale($this->communalTax),
            churchTax: $scale($this->churchTax),
            totalIncomeTax: $scale($this->totalIncomeTax),
            totalTaxBurden: $scale($this->totalTaxBurden),
            monthlyReserve: $scale($this->monthlyReserve),
            effectiveRate: $this->effectiveRate,
            projectedAnnualRevenue: $this->projectedAnnualRevenue,
            projectedTotalTax: $scale($this->projectedTotalTax),
            projectedMonthlyReserve: $scale($this->projectedMonthlyReserve),
            lossYear: $this->lossYear,
            ageExemptionApplied: $this->ageExemptionApplied,
            ratesSnapshot: $this->ratesSnapshot,
            pillar3aDeduction: $scale($this->pillar3aDeduction),
            childDeduction: $scale($this->childDeduction),
            minimumContributionApplied: $this->minimumContributionApplied,
        );
    }

    /**
     * A CHF amount multiplied by $share (a decimal fraction), rounded to CHF 0.01.
     */
    public static function scaleAmount(float $value, string $share): float
    {
        return round((float) bcmul(number_format($value, 2, '.', ''), $share, 6), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quellensteuer_regime' => $this->quellensteuerRegime,
            'gross_revenue' => $this->grossRevenue,
            'total_expenses' => $this->totalExpenses,
            'net_income' => $this->netIncome,
            'ahv_contribution' => $this->ahvContribution,
            'iv_contribution' => $this->ivContribution,
            'eo_contribution' => $this->eoContribution,
            'total_social_insurance' => $this->totalSocialInsurance,
            'ahv_deduction' => $this->ahvDeduction,
            'taxable_income' => $this->taxableIncome,
            'federal_tax' => $this->federalTax,
            'cantonal_tax' => $this->cantonalTax,
            'communal_tax' => $this->communalTax,
            'church_tax' => $this->churchTax,
            'total_income_tax' => $this->totalIncomeTax,
            'total_tax_burden' => $this->totalTaxBurden,
            'monthly_reserve' => $this->monthlyReserve,
            'effective_rate' => $this->effectiveRate,
            'projected_annual_revenue' => $this->projectedAnnualRevenue,
            'projected_total_tax' => $this->projectedTotalTax,
            'projected_monthly_reserve' => $this->projectedMonthlyReserve,
        ];
    }
}
