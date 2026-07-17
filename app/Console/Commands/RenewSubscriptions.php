<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;

class RenewSubscriptions extends Command
{
    protected $signature = 'settlo:renew-subscriptions';

    protected $description = 'Renew active subscriptions at period end, applying any scheduled downgrade.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = 0;

        Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::PastDue])
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->each(function (Subscription $subscription) use ($subscriptions, &$count) {
                $subscriptions->renew($subscription);
                $count++;
            });

        $this->info("Renewed {$count} subscription(s).");

        return self::SUCCESS;
    }
}
