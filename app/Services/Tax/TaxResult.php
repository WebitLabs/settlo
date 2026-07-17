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
        public bool $lossYear,
        public bool $ageExemptionApplied,
        /** @var array<string, mixed> */
        public array $ratesSnapshot,
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
            projectedAnnualRevenue: 0, projectedTotalTax: 0,
            lossYear: false, ageExemptionApplied: false, ratesSnapshot: [],
        );
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
        ];
    }
}
