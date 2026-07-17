<?php

use Illuminate\Console\Scheduling\Schedule;

it('schedules the horizon snapshot command every five minutes', function () {
    $schedule = app(Schedule::class);

    $snapshot = collect($schedule->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'horizon:snapshot'));

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->expression)->toBe('*/5 * * * *');
});
