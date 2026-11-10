<?php

use App\Jobs\RecalculateTaxEstimation;
use App\Models\Canton;
use App\Models\Commune;
use App\Models\Plan;
use App\Models\PostalCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('seeds the reference data and reports the populated tables', function () {
    $this->artisan('settlo:deploy', ['--skip-migrations' => true])
        ->expectsOutputToContain('Deploy complete')
        ->assertSuccessful();

    expect(Canton::count())->toBe(26)
        ->and(Commune::count())->toBeGreaterThan(2000)
        ->and(PostalCode::count())->toBeGreaterThan(0)
        ->and(Plan::count())->toBe(3);
});

it('is idempotent', function () {
    $this->artisan('settlo:deploy', ['--skip-migrations' => true])->assertSuccessful();
    $communes = Commune::count();

    $this->artisan('settlo:deploy', ['--skip-migrations' => true])->assertSuccessful();

    expect(Commune::count())->toBe($communes);
});

it('runs the migrations by default', function () {
    $this->artisan('settlo:deploy')->assertSuccessful();

    expect(Canton::count())->toBe(26);
});

it('does nothing on the sync connection when draining the queue', function () {
    expect(config('queue.default'))->toBe('sync');

    $this->artisan('settlo:drain-queue')
        ->expectsOutputToContain('jobs run inline')
        ->assertSuccessful();
});

it('drains queued jobs on a real connection', function () {
    config(['queue.default' => 'database']);

    dispatch(new RecalculateTaxEstimation((string) Str::uuid()));
    expect(DB::table('jobs')->count())->toBe(1);

    // Runs inside an already-booted process, so the worker's 128 MB default
    // would otherwise stop it before it picked anything up.
    $this->artisan('settlo:drain-queue', ['--max-time' => 5])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0);
});
