<?php

namespace App\Providers;

use App\Billing\DummyGateway;
use App\Billing\PaymentGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Payment provider is swappable via config; the POC ships the dummy.
        $this->app->bind(PaymentGateway::class, function () {
            return match (config('settlo.payment_gateway', 'dummy')) {
                default => new DummyGateway,
            };
        });
    }

    public function boot(): void
    {
        // Surface N+1 queries during local development.
        Model::preventLazyLoading($this->app->environment('local'));
    }
}
