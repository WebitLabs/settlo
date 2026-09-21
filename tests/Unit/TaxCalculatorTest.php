<?php

use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Services\Tax\TaxCalculator;
use App\Services\Tax\TaxInput;
use Database\Seeders\ReferenceDataSeeder;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->calc = app(TaxCalculator::class);
});

/**
 * The canonical fixture from Settlo Tax Engine Algorithms v2.0, Section 8
 * (Anna Müller, Zürich). This is the authoritative expected output — the
 * mockup's CHF 22,100 figure is stale and intentionally ignored.
 */
it('reproduces the authoritative Anna Müller fixture', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH',
        fiscalYear: 2026,
        grossRevenue: 68400,
        deductibleExpenses: 14200,
        maritalStatus: MaritalStatus::Single,
        numberOfChildren: 0,
        pillar3aAmount: 7056,
        age: 34,
        communeMultiplier: 119,
        daysElapsed: 153,
    ));

    expect($result->netIncome)->toBe(54200.00)
        ->and($result->totalSocialInsurance)->toBe(6775.00)
        ->and($result->ahvDeduction)->toBe(2872.60)
        ->and($result->taxableIncome)->toBe(44271.40)
        ->and($result->federalTax)->toBe(293.70)
        ->and($result->cantonalTax)->toBe(3541.71)
        ->and($result->communalTax)->toBe(4214.64)
        ->and($result->totalIncomeTax)->toBe(8050.05)
        ->and($result->totalTaxBurden)->toBe(14825.05)
        ->and($result->monthlyReserve)->toBe(1235.42)
        ->and($result->effectiveRate)->toBe(21.7);
});

it('annualises the full-year projection from days elapsed', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 68400, deductibleExpenses: 14200,
        pillar3aAmount: 7056, communeMultiplier: 119, daysElapsed: 153,
    ));

    // 68,400 / 153 × 365 ≈ 163,137 — well above the YTD figure.
    expect($result->projectedAnnualRevenue)->toBeGreaterThan(160000.0)
        ->and($result->projectedTotalTax)->toBeGreaterThan($result->totalTaxBurden);
});

it('matches the canton comparison fixtures (no 3a, no church)', function (string $canton, float $expectedTotal) {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: $canton, fiscalYear: 2026,
        grossRevenue: 68400, deductibleExpenses: 14200,
        maritalStatus: MaritalStatus::Single, numberOfChildren: 0,
        pillar3aAmount: 0,
    ));

    expect($result->totalTaxBurden)->toEqualWithDelta($expectedTotal, 1.0);
})->with([
    'Zug (lowest)' => ['ZG', 10223.0],
    'Zürich' => ['ZH', 16248.0],
    'Neuchâtel' => ['NE', 16740.0],
    'Jura (highest)' => ['JU', 18260.0],
]);

it('stops with a Quellensteuer flag for B-permit holders', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 68400, deductibleExpenses: 14200,
        residencePermit: ResidencePermit::EuEftaPermitB,
    ));

    expect($result->quellensteuerRegime)->toBeTrue()
        ->and($result->totalTaxBurden)->toBe(0.0);
});

it('flags a loss year and charges no income tax', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 20000, deductibleExpenses: 30000,
    ));

    expect($result->lossYear)->toBeTrue()
        ->and($result->totalIncomeTax)->toBe(0.0)
        ->and($result->netIncome)->toBe(-10000.00);
});

it('caps Pillar 3a at the self-employed maximum', function () {
    $capped = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 200000, deductibleExpenses: 0,
        pillar3aAmount: 50000, communeMultiplier: 119,
    ));

    $atCap = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 200000, deductibleExpenses: 0,
        pillar3aAmount: 35280, communeMultiplier: 119,
    ));

    // A declared 3a above the cap is silently limited to the maximum, so both
    // calculations produce the same taxable income. 20 % of 200,000 is above
    // the cap, so the absolute maximum applies.
    expect($capped->taxableIncome)->toBe($atCap->taxableIncome)
        ->and($capped->pillar3aDeduction)->toBe(35280.00)
        ->and($capped->ratesSnapshot['pillar3a_max'])->toBe(35280.00);
});

it('limits Pillar 3a without a pension fund to 20 % of net income', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 60000, deductibleExpenses: 10000,
        pillar3aAmount: 35280,
    ));

    expect($result->pillar3aDeduction)->toBe(10000.00)
        ->and($result->ratesSnapshot['pillar3a_max'])->toBe(10000.00)
        ->and($result->ratesSnapshot['deductions']['pillar3a'])->toBe(10000.00);
});

it('keeps the small Pillar 3a cap with a pension fund regardless of income', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 20000, deductibleExpenses: 0,
        pillar3aAmount: 10000, hasPillar2: true,
    ));

    // 20 % of 20,000 would be 4,000, but that limit only applies without a pension fund.
    expect($result->pillar3aDeduction)->toBe(7056.00);
});

it('allows no Pillar 3a deduction without a pension fund in a loss year', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 10000, deductibleExpenses: 15000,
        pillar3aAmount: 5000,
    ));

    expect($result->pillar3aDeduction)->toBe(0.0);
});

it('guards against division by zero at the start of the year', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 0, deductibleExpenses: 0,
        daysElapsed: 0,
    ));

    // No revenue means no income tax, but a self-employed person still owes the
    // minimum AHV contribution (spec section 11, "Zero revenue").
    expect($result->totalIncomeTax)->toBe(0.0)
        ->and($result->totalSocialInsurance)->toBe(514.00)
        ->and($result->totalTaxBurden)->toBe(514.00)
        ->and($result->minimumContributionApplied)->toBeTrue()
        ->and($result->effectiveRate)->toBe(0.0)
        ->and($result->projectedAnnualRevenue)->toBe(0.0);
});

it('apportions the minimum AHV contribution and deducts half of the AHV actually charged', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 966.41, deductibleExpenses: 0,
        maritalStatus: MaritalStatus::Single,
    ));

    expect($result->totalSocialInsurance)->toBe(514.00)
        ->and(round($result->ahvContribution + $result->ivContribution + $result->eoContribution, 2))->toBe(514.00)
        ->and($result->ahvDeduction)->toBe(round($result->ahvContribution * 0.5, 2))
        ->and($result->ahvDeduction)->toBe(217.94)
        ->and($result->taxableIncome)->toBe(748.47)
        ->and($result->minimumContributionApplied)->toBeTrue()
        ->and($result->ratesSnapshot['deductions'])->toMatchArray([
            'ahv' => 217.94,
            'pillar3a' => 0.0,
            'children' => 0.0,
            'minimum_contribution_applied' => true,
        ]);
});

it('does not apply the minimum contribution above the threshold', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 100000, deductibleExpenses: 0,
    ));

    expect($result->totalSocialInsurance)->toBe(12500.00)
        ->and($result->ahvContribution)->toBe(10600.00)
        ->and($result->ahvDeduction)->toBe(5300.00)
        ->and($result->taxableIncome)->toBe(94700.00)
        ->and($result->minimumContributionApplied)->toBeFalse();
});

it('charges no minimum contribution to a 65+ person whose income is fully exempt', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 10000, deductibleExpenses: 0,
        age: 67,
    ));

    expect($result->totalSocialInsurance)->toBe(0.0)
        ->and($result->ahvDeduction)->toBe(0.0)
        ->and($result->minimumContributionApplied)->toBeFalse()
        ->and($result->ageExemptionApplied)->toBeTrue();
});

it('exposes the Pillar 3a and child deductions', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 80000, deductibleExpenses: 0,
        numberOfChildren: 2, pillar3aAmount: 5000,
    ));

    expect($result->pillar3aDeduction)->toBe(5000.00)
        ->and($result->childDeduction)->toBeGreaterThan(0.0)
        ->and($result->ratesSnapshot['deductions']['children'])->toBe($result->childDeduction);
});

it('stops for every B, L and G residence status and calculates for Swiss citizens and C permits', function (ResidencePermit $status) {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 68400, deductibleExpenses: 14200,
        residencePermit: $status,
    ));

    $expectsQuellensteuer = in_array($status, [
        ResidencePermit::EuEftaPermitB, ResidencePermit::EuEftaPermitL,
        ResidencePermit::NonEuPermitB, ResidencePermit::NonEuPermitL,
        ResidencePermit::CrossBorderPermitG,
    ], true);

    expect($result->quellensteuerRegime)->toBe($expectsQuellensteuer)
        ->and($result->totalTaxBurden > 0)->toBe(! $expectsQuellensteuer);
})->with(ResidencePermit::cases());

it('uses the married (Tariff B) federal brackets for dual-income couples (Tariff C)', function () {
    $input = fn (MaritalStatus $status): TaxInput => new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 120000, deductibleExpenses: 0,
        maritalStatus: $status,
    );

    $dualIncome = $this->calc->calculate($input(MaritalStatus::MarriedDualIncome));

    expect(MaritalStatus::MarriedDualIncome->tariff())->toBe('B')
        ->and($dualIncome->federalTax)->toBe($this->calc->calculate($input(MaritalStatus::Married))->federalTax)
        ->and($dualIncome->federalTax)->not->toBe($this->calc->calculate($input(MaritalStatus::Single))->federalTax);
});

describe('church tax (step 7)', function () {
    it('charges the canton church rate on the cantonal simple tax for a member', function () {
        $member = $this->calc->calculate(new TaxInput(
            cantonCode: 'ZH', fiscalYear: 2026,
            grossRevenue: 68400, deductibleExpenses: 14200,
            pillar3aAmount: 7056, kirchensteuer: true, communeMultiplier: 119, daysElapsed: 153,
        ));

        // Zurich: cantonal rate 8 %, church rate 10 % of the cantonal simple tax.
        expect($member->churchTax)->toBe(round($member->cantonalTax * 0.10, 2))
            ->and($member->churchTax)->toBe(354.17)
            ->and($member->ratesSnapshot['church_rate'])->toBe(10.0);
    });

    it('charges nothing and leaves the rest of the bill untouched for a non-member', function () {
        $input = fn (bool $member): TaxInput => new TaxInput(
            cantonCode: 'ZH', fiscalYear: 2026,
            grossRevenue: 68400, deductibleExpenses: 14200,
            pillar3aAmount: 7056, kirchensteuer: $member, communeMultiplier: 119, daysElapsed: 153,
        );

        $member = $this->calc->calculate($input(true));
        $nonMember = $this->calc->calculate($input(false));

        expect($nonMember->churchTax)->toBe(0.0)
            ->and($nonMember->federalTax)->toBe($member->federalTax)
            ->and($nonMember->cantonalTax)->toBe($member->cantonalTax)
            ->and($nonMember->communalTax)->toBe($member->communalTax)
            ->and($nonMember->totalIncomeTax + $member->churchTax)->toEqualWithDelta($member->totalIncomeTax, 0.02);
    });

    it('charges no church tax on a taxable income of zero', function () {
        $result = $this->calc->calculate(new TaxInput(
            cantonCode: 'ZH', fiscalYear: 2026,
            grossRevenue: 5000, deductibleExpenses: 20000,
            kirchensteuer: true,
        ));

        expect($result->churchTax)->toBe(0.0)
            ->and($result->totalIncomeTax)->toBe(0.0);
    });
});

it('falls back to the canton default multiplier when no commune is chosen', function () {
    $withoutCommune = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 68400, deductibleExpenses: 14200,
        pillar3aAmount: 7056, communeMultiplier: null, daysElapsed: 153,
    ));

    $atCantonDefault = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 68400, deductibleExpenses: 14200,
        pillar3aAmount: 7056, communeMultiplier: 119, daysElapsed: 153,
    ));

    // Zurich's seeded fallback Steuerfuss is the capital's, 119 %.
    expect($withoutCommune->ratesSnapshot['communal_multiplier'])->toBe(119.0)
        ->and($withoutCommune->communalTax)->toBe($atCantonDefault->communalTax)
        ->and($withoutCommune->totalTaxBurden)->toBe($atCantonDefault->totalTaxBurden);
});

it('uses a chosen commune multiplier in place of the canton default', function () {
    $cheapCommune = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 68400, deductibleExpenses: 14200,
        pillar3aAmount: 7056, communeMultiplier: 72, daysElapsed: 153,
    ));

    expect($cheapCommune->ratesSnapshot['communal_multiplier'])->toBe(72.0)
        ->and($cheapCommune->communalTax)->toBe(round($cheapCommune->cantonalTax * 0.72, 2))
        ->and($cheapCommune->communalTax)->toBeLessThan($cheapCommune->cantonalTax);
});

describe('the AHV age-65 exemption', function () {
    it('exempts only the first CHF 16,800 when income exceeds it', function () {
        $senior = $this->calc->calculate(new TaxInput(
            cantonCode: 'ZH', fiscalYear: 2026,
            grossRevenue: 30000, deductibleExpenses: 0,
            age: 67,
        ));

        // 30,000 − 16,800 = 13,200 contributable at 12.5 % (10.6 + 1.4 + 0.5).
        expect($senior->ageExemptionApplied)->toBeTrue()
            ->and($senior->minimumContributionApplied)->toBeFalse()
            ->and($senior->ahvContribution)->toBe(1399.20)
            ->and($senior->ivContribution)->toBe(184.80)
            ->and($senior->eoContribution)->toBe(66.00)
            ->and($senior->totalSocialInsurance)->toBe(1650.00)
            ->and($senior->ahvDeduction)->toBe(699.60)
            ->and($senior->ratesSnapshot['age_exemption_amount'])->toBe(16800.0)
            ->and($senior->ratesSnapshot['deductions']['age_exemption_applied'])->toBeTrue();
    });

    it('charges the same person under 65 on the whole income', function () {
        $younger = $this->calc->calculate(new TaxInput(
            cantonCode: 'ZH', fiscalYear: 2026,
            grossRevenue: 30000, deductibleExpenses: 0,
            age: 64,
        ));

        expect($younger->ageExemptionApplied)->toBeFalse()
            ->and($younger->totalSocialInsurance)->toBe(3750.00);
    });

    it('still charges the minimum when the exemption only partly covers the income', function () {
        // 20,000 − 16,800 = 3,200 at 12.5 % is 400, below the CHF 514 minimum.
        $result = $this->calc->calculate(new TaxInput(
            cantonCode: 'ZH', fiscalYear: 2026,
            grossRevenue: 20000, deductibleExpenses: 0,
            age: 70,
        ));

        expect($result->ageExemptionApplied)->toBeTrue()
            ->and($result->minimumContributionApplied)->toBeTrue()
            ->and($result->totalSocialInsurance)->toBe(514.00);
    });
});

describe('the minimum AHV contribution', function () {
    it('applies in a loss year even though no income tax is due', function () {
        $result = $this->calc->calculate(new TaxInput(
            cantonCode: 'ZH', fiscalYear: 2026,
            grossRevenue: 20000, deductibleExpenses: 30000,
        ));

        expect($result->lossYear)->toBeTrue()
            ->and($result->totalIncomeTax)->toBe(0.0)
            ->and($result->totalSocialInsurance)->toBe(514.00)
            ->and($result->minimumContributionApplied)->toBeTrue()
            ->and($result->totalTaxBurden)->toBe(514.00)
            ->and($result->ratesSnapshot['deductions']['loss_year'])->toBeTrue();
    });

    it('applies at zero revenue', function () {
        $result = $this->calc->calculate(new TaxInput(
            cantonCode: 'ZH', fiscalYear: 2026,
            grossRevenue: 0, deductibleExpenses: 0,
        ));

        expect($result->lossYear)->toBeFalse()
            ->and($result->totalSocialInsurance)->toBe(514.00)
            ->and($result->minimumContributionApplied)->toBeTrue();
    });

    it('does not apply when the age exemption covers the whole income', function () {
        $result = $this->calc->calculate(new TaxInput(
            cantonCode: 'ZH', fiscalYear: 2026,
            grossRevenue: 10000, deductibleExpenses: 0,
            age: 67,
        ));

        expect($result->totalSocialInsurance)->toBe(0.0)
            ->and($result->minimumContributionApplied)->toBeFalse()
            ->and($result->ageExemptionApplied)->toBeTrue();
    });
});

it('derives the monthly set-aside from the projected full year, not from the year to date', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 68400, deductibleExpenses: 14200,
        pillar3aAmount: 7056, communeMultiplier: 119, daysElapsed: 153,
    ));

    expect($result->projectedMonthlyReserve)->toBe(round($result->projectedTotalTax / 12, 2))
        ->and($result->monthlyReserve)->toBe(round($result->totalTaxBurden / 12, 2))
        ->and($result->projectedMonthlyReserve)->toBeGreaterThan($result->monthlyReserve);
});

it('leaves the two reserves equal on full-year figures', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 68400, deductibleExpenses: 14200,
        pillar3aAmount: 7056, communeMultiplier: 119, daysElapsed: 365,
    ));

    expect($result->projectedAnnualRevenue)->toBe(68400.00)
        ->and($result->projectedTotalTax)->toBe($result->totalTaxBurden)
        ->and($result->projectedMonthlyReserve)->toBe($result->monthlyReserve);
});
