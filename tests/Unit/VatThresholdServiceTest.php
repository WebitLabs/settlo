<?php

use App\Models\VatConfig;
use App\Services\Tax\RateRepository;
use App\Services\Tax\VatThresholdService;

it('does not divide by zero on the mandatory branch when the threshold is zero', function () {
    $rates = Mockery::mock(RateRepository::class);
    $rates->shouldReceive('vatConfig')
        ->andReturn(new VatConfig(['registration_threshold' => 0]));

    $service = new VatThresholdService($rates);

    // A single invoice >= a zero threshold forces the mandatory branch, which
    // previously divided revenue by zero.
    $result = $service->evaluate(
        revenueYtd: 50000,
        daysElapsed: 180,
        fiscalYear: 2026,
        largestSingleInvoice: 60000,
    );

    expect($result['level'])->toBe('mandatory')
        ->and($result['progress_pct'])->toBe(0.0)
        ->and($result['threshold'])->toBe(0);
});
