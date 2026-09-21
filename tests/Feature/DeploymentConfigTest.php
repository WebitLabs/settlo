<?php

use App\Http\Controllers\CronCommandController;
use App\Jobs\ProcessReceiptUpload;
use App\Jobs\RecalculatePersonalTaxEstimation;
use App\Jobs\RecalculateTaxEstimation;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Panel;

/**
 * @return array<string, mixed>
 */
function vercelConfig(): array
{
    return json_decode((string) file_get_contents(base_path('vercel.json')), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The Vercel Hobby plan allows at most two cron jobs, at daily granularity, so
 * only the two that must not be missed are scheduled by the platform. The rest
 * (quota resets, renewals, the queue drain) run from an external pinger against
 * the same endpoints — see the cron section of .env.example. On Pro, add them
 * back here.
 */
it('schedules the lifecycle commands the hosting plan allows', function (string $command) {
    $paths = array_column(vercelConfig()['crons'], 'path');

    expect($paths)->toContain("/cron/{$command}");
})->with(['expire-trials', 'mark-overdue-invoices']);

it('stays within the two daily cron jobs the Hobby plan allows', function () {
    $crons = vercelConfig()['crons'];

    expect($crons)->toHaveCount(2);

    foreach ($crons as $cron) {
        // A daily schedule has a fixed minute and hour: "m h * * *".
        expect($cron['schedule'])->toMatch('/^\d+ \d+ \* \* \*$/');
    }
});

it('only schedules paths the cron controller actually accepts', function () {
    foreach (vercelConfig()['crons'] as $cron) {
        $command = basename(parse_url($cron['path'], PHP_URL_PATH));

        expect(CronCommandController::COMMANDS)->toHaveKey($command)
            ->and($cron['schedule'])->toMatch('/^[\d*\/,\- ]+$/');
    }
});

it('serves the panel favicon directory as a static route', function () {
    $sources = array_column(vercelConfig()['routes'], 'src');

    expect($sources)->toContain('/images/(.*)')
        ->and(file_exists(public_path('images/settlo-icon-32.png')))->toBeTrue();
});

it('keeps the serverless function within a 60 second budget', function () {
    expect(vercelConfig()['functions']['api/index.php']['maxDuration'])->toBe(60);
});

it('drains the queue well inside the function execution budget', function () {
    expect((int) config('settlo.queue_drain.max_time'))->toBeLessThan(60);
});

it('gives every queued job retries, a timeout and a failure handler', function (string $job) {
    $instance = new ReflectionClass($job);

    expect($instance->getDefaultProperties()['tries'] ?? null)->toBeGreaterThan(1)
        ->and($instance->getDefaultProperties()['timeout'] ?? null)->toBeGreaterThan(0)
        ->and($instance->getDefaultProperties()['timeout'])->toBeLessThanOrEqual(60)
        ->and($instance->hasMethod('failed'))->toBeTrue();
})->with([
    ProcessReceiptUpload::class,
    RecalculateTaxEstimation::class,
    RecalculatePersonalTaxEstimation::class,
]);

it('defaults the fiscal year to the current year', function () {
    expect(config('settlo.current_fiscal_year'))->toBe((int) now()->setTimezone('Europe/Zurich')->format('Y'));
});

it('hides the horizon link when the queue driver is not redis', function () {
    expect(config('queue.default'))->not->toBe('redis');

    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

    expect($panel->getNavigationItems())->toBeEmpty();
});

it('shows the horizon link on the redis queue driver', function () {
    config(['queue.default' => 'redis']);

    $panel = (new AdminPanelProvider(app()))->panel(Panel::make());

    expect(collect($panel->getNavigationItems())->map(fn ($item) => $item->getLabel())->all())->toBe(['Horizon']);
});
