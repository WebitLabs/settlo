<?php

use App\Models\VatConfig;
use App\Services\Tax\RateRepository;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->rates = app(RateRepository::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('resolves the rates of the current year on today', function () {
    Carbon::setTestNow('2026-06-15');

    expect($this->rates->referenceDate(2026)->toDateString())->toBe('2026-06-15')
        ->and($this->rates->vatConfig(2026)->year)->toBe(2026)
        ->and($this->rates->socialInsuranceRate(2026)->year)->toBe(2026)
        ->and($this->rates->cantonConfig('ZH', 2026)->year)->toBe(2026);
});

it('resolves a past year on its last day rather than today', function () {
    Carbon::setTestNow('2027-03-01');

    expect($this->rates->referenceDate(2026)->toDateString())->toBe('2026-12-31');
});

it('does not apply next year\'s rates before they take effect', function () {
    Carbon::setTestNow('2026-10-01');

    VatConfig::create([
        'year' => 2027,
        'standard_rate' => 9.0,
        'reduced_rate' => 2.8,
        'special_rate' => 4.0,
        'registration_threshold' => 120000,
        'registration_window_days' => 30,
        'effective_from' => '2027-01-01',
        'effective_to' => null,
    ]);

    // Seeded in October, but only in force from 1 January.
    expect($this->rates->vatConfig(2026)->registration_threshold)->toBe(100000)
        ->and($this->rates->vatConfig(2027)->registration_threshold)->toBe(120000);
});

it('keeps using a still-open config for a year that has none of its own', function () {
    // The 2026 config has no effective_to, so it stays in force in 2027.
    expect($this->rates->vatConfig(2027)->year)->toBe(2026);
});

it('stops using a config once its effective window has closed', function () {
    VatConfig::where('year', 2026)->update(['effective_to' => '2026-12-31']);

    expect(fn (): VatConfig => $this->rates->vatConfig(2027))
        ->toThrow(RuntimeException::class, 'No VAT config for 2027.');
});

it('builds the VAT rate options from the config rather than hardcoding them', function () {
    expect($this->rates->vatRateOptions(2026))->toBe([
        '8.1' => '8.1% (standard)',
        '2.6' => '2.6% (reduced)',
        '3.8' => '3.8% (accommodation)',
        '0' => '0% (exempt / export)',
    ])->and($this->rates->standardVatRate(2026))->toBe('8.1');
});

it('follows a rate change through to the options', function () {
    VatConfig::where('year', 2026)->update(['standard_rate' => 8.5, 'reduced_rate' => 2.7]);

    expect($this->rates->vatRateOptions(2026))->toHaveKeys(['8.5', '2.7'])
        ->and($this->rates->standardVatRate(2026))->toBe('8.5');
});
