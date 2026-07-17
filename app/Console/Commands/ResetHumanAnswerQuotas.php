<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;

class ResetHumanAnswerQuotas extends Command
{
    protected $signature = 'settlo:reset-quotas';

    protected $description = 'Reset monthly human-answer quotas (calendar month, no rollover).';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = 0;

        Subscription::query()
            ->whereNotNull('quota_reset_at')
            ->where('quota_reset_at', '<=', now())
            ->each(function (Subscription $subscription) use ($subscriptions, &$count) {
                $subscriptions->resetQuota($subscription);
                $count++;
            });

        $this->info("Reset quota for {$count} subscription(s).");

        return self::SUCCESS;
    }
}
