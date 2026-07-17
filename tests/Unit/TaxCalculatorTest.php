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
        residencePermit: ResidencePermit::BPermit,
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
        grossRevenue: 120000, deductibleExpenses: 0,
        pillar3aAmount: 50000, communeMultiplier: 119,
    ));

    $atCap = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 120000, deductibleExpenses: 0,
        pillar3aAmount: 35280, communeMultiplier: 119,
    ));

    // A declared 3a above the cap is silently limited to the maximum, so both
    // calculations produce the same taxable income.
    expect($capped->taxableIncome)->toBe($atCap->taxableIncome);
});

it('guards against division by zero at the start of the year', function () {
    $result = $this->calc->calculate(new TaxInput(
        cantonCode: 'ZH', fiscalYear: 2026,
        grossRevenue: 0, deductibleExpenses: 0,
        daysElapsed: 0,
    ));

    expect($result->totalTaxBurden)->toBe(0.0)
        ->and($result->effectiveRate)->toBe(0.0);
});
