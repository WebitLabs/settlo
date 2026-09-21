<?php

use App\Http\Controllers\CronCommandController;
use App\Models\Canton;
use Illuminate\Support\Facades\Artisan;

it('runs a whitelisted command with a valid bearer token', function () {
    config(['cron.secret' => 'test-secret']);

    $response = $this->withHeader('Authorization', 'Bearer test-secret')
        ->getJson('/cron/expire-trials');

    $response->assertSuccessful()
        ->assertJsonStructure(['command', 'exit_code', 'duration_ms'])
        ->assertJson([
            'command' => 'settlo:expire-trials',
            'exit_code' => 0,
        ]);
});

it('accepts the token as a query parameter fallback', function () {
    config(['cron.secret' => 'test-secret']);

    $this->getJson('/cron/reset-quotas?token=test-secret')
        ->assertSuccessful()
        ->assertJson(['command' => 'settlo:reset-quotas', 'exit_code' => 0]);
});

it('rejects an invalid token', function () {
    config(['cron.secret' => 'test-secret']);

    $this->withHeader('Authorization', 'Bearer wrong-secret')
        ->getJson('/cron/expire-trials')
        ->assertForbidden();
});

it('rejects a request with no token at all', function () {
    config(['cron.secret' => 'test-secret']);

    $this->getJson('/cron/expire-trials')->assertForbidden();
});

it('responds service unavailable when no cron secret is configured', function () {
    config(['cron.secret' => null]);

    $this->withHeader('Authorization', 'Bearer anything')
        ->getJson('/cron/expire-trials')
        ->assertServiceUnavailable();
});

it('returns not found for a command outside the whitelist', function () {
    config(['cron.secret' => 'test-secret']);

    $this->withHeader('Authorization', 'Bearer test-secret')
        ->getJson('/cron/horizon-snapshot')
        ->assertNotFound();
});

it('exposes the queue drain so a deploy without a worker still runs queued jobs', function () {
    config(['cron.secret' => 'test-secret']);

    $this->getJson('/cron/drain-queue?token=test-secret')
        ->assertSuccessful()
        ->assertJson(['command' => 'settlo:drain-queue', 'exit_code' => 0]);
});

it('exposes the deploy step so reference data can be seeded without a shell', function () {
    config(['cron.secret' => 'test-secret']);

    $this->getJson('/cron/deploy?token=test-secret')
        ->assertSuccessful()
        ->assertJson(['command' => 'settlo:deploy', 'exit_code' => 0]);

    expect(Canton::count())->toBe(26);
});

it('answers with a server error when the command itself fails', function () {
    config(['cron.secret' => 'test-secret']);

    // A failing command must not look like a healthy ping to the scheduler.
    Artisan::shouldReceive('call')
        ->once()
        ->with('settlo:expire-trials')
        ->andReturn(1);

    // The response carries the command's output so the failure is diagnosable.
    Artisan::shouldReceive('output')
        ->once()
        ->andReturn('Something went wrong.');

    $this->getJson('/cron/expire-trials?token=test-secret')
        ->assertStatus(500)
        ->assertJson(['exit_code' => 1]);
});

it('returns the command output so a failure and the demo passwords are visible', function () {
    config(['cron.secret' => 'test-secret']);

    $response = $this->withHeader('Authorization', 'Bearer test-secret')
        ->getJson('/cron/expire-trials');

    $response->assertOk()->assertJsonStructure(['command', 'exit_code', 'duration_ms', 'output']);
});

it('can seed the demo fixtures on a deployed environment', function () {
    config(['cron.secret' => 'test-secret']);

    // The endpoint forces the seeder, so a test environment needs no extra flag.
    expect(CronCommandController::COMMANDS)
        ->toHaveKey('seed-demo')
        ->and(CronCommandController::COMMANDS['seed-demo'])
        ->toContain('--force');
});
