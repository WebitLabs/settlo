<?php

use Illuminate\Console\Scheduling\Schedule;

it('does not schedule the horizon snapshot when the queue driver is not redis', function () {
    expect(config('queue.default'))->toBe('sync');

    $snapshot = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', 'horizon:snapshot'));

    expect($snapshot)->toBeNull();
});

it('keeps the settlo lifecycle commands scheduled regardless of queue driver', function (string $command) {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains($event->command ?? '', $command));

    expect($event)->not->toBeNull();
})->with([
    'settlo:expire-trials',
    'settlo:reset-quotas',
    'settlo:renew-subscriptions',
    'settlo:mark-overdue-invoices',
]);
