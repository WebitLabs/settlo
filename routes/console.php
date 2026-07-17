<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Billing lifecycle
Schedule::command('settlo:expire-trials')->dailyAt('01:00');
Schedule::command('settlo:reset-quotas')->dailyAt('00:05');
Schedule::command('settlo:renew-subscriptions')->hourly();

// Invoicing
Schedule::command('settlo:mark-overdue-invoices')->dailyAt('02:00');
