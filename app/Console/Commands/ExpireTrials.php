<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;

class ExpireTrials extends Command
{
    protected $signature = 'settlo:expire-trials';

    protected $description = 'Lock subscriptions whose trial has ended without a paid plan into a read-only state.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->each(function (Subscription $subscription) use ($subscriptions, &$count) {
                $subscriptions->expire($subscription);
                $count++;
            });

        $this->info("Expired {$count} trial(s).");

        return self::SUCCESS;
    }
}
