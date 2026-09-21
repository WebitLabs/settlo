<?php

use App\Models\VatConfig;
use App\Services\Tax\RateRepository;
use App\Services\Tax\VatThresholdService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Carbon;

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

describe('against the seeded CHF 100,000 threshold', function () {
    beforeEach(function () {
        $this->seed(ReferenceDataSeeder::class);
        $this->service = app(VatThresholdService::class);
    });

    it('escalates on the exact band boundaries', function (float $revenue, string $level) {
        expect($this->service->evaluate($revenue, 180, 2026)['level'])->toBe($level);
    })->with([
        'just under 60 %' => [59_999.99, 'none'],
        'exactly 60 %' => [60_000.0, 'info'],
        'just under 75 %' => [74_999.99, 'info'],
        'exactly 75 %' => [75_000.0, 'warning'],
        'just under 90 %' => [89_999.99, 'warning'],
        'exactly 90 %' => [90_000.0, 'critical'],
        'just under 100 %' => [99_999.99, 'critical'],
        'exactly 100 %' => [100_000.0, 'mandatory'],
        'well over' => [140_000.0, 'mandatory'],
    ]);

    it('makes registration mandatory on a single invoice at the threshold whatever the YTD total', function () {
        $result = $this->service->evaluate(
            revenueYtd: 100.0,
            daysElapsed: 30,
            fiscalYear: 2026,
            largestSingleInvoice: 100_000.0,
        );

        expect($result['level'])->toBe('mandatory')
            ->and($result['single_invoice'])->toBeTrue()
            ->and($result['crossing_date'])->toBeNull()
            ->and($result['progress_pct'])->toBe(0.1)
            ->and($result['registration_window_days'])->toBe(30);
    });

    it('leaves a single invoice one franc below the threshold on the ladder', function () {
        $result = $this->service->evaluate(
            revenueYtd: 99_999.0,
            daysElapsed: 30,
            fiscalYear: 2026,
            largestSingleInvoice: 99_999.0,
        );

        expect($result['level'])->toBe('critical')
            ->and($result['single_invoice'])->toBeFalse();
    });

    it('projects the crossing date from the average daily revenue', function () {
        Carbon::setTestNow('2026-04-10');

        // 50,000 over 100 days is 500/day, so the remaining 50,000 takes
        // another 100 days: (100000 − 50000) / 500 = 100.
        $result = $this->service->evaluate(revenueYtd: 50_000.0, daysElapsed: 100, fiscalYear: 2026);

        expect($result['days_to_threshold'])->toBe(100)
            ->and($result['crossing_date'])->toBe('2026-07-19')
            ->and(Carbon::now()->diffInDays(Carbon::parse($result['crossing_date'])))->toBe(100.0);

        Carbon::setTestNow();
    });

    it('rounds a partial crossing day up', function () {
        Carbon::setTestNow('2026-04-10');

        // 31,000 over 90 days is 344.44/day → 200.32 days to go, which must
        // round up to a whole day rather than truncate.
        $result = $this->service->evaluate(revenueYtd: 31_000.0, daysElapsed: 90, fiscalYear: 2026);

        expect($result['days_to_threshold'])->toBe(201)
            ->and($result['crossing_date'])->toBe(Carbon::parse('2026-04-10')->addDays(201)->toDateString());

        Carbon::setTestNow();
    });

    it('has no crossing date without revenue or once the threshold is passed', function (float $revenue) {
        expect($this->service->evaluate($revenue, 120, 2026)['crossing_date'])->toBeNull();
    })->with([
        'no revenue' => [0.0],
        'already over' => [120_000.0],
    ]);
});
