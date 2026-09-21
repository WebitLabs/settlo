<?php

namespace App\Console\Commands;

use App\Billing\DummyGateway;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;

class RenewSubscriptions extends Command
{
    protected $signature = 'settlo:renew-subscriptions';

    protected $description = 'Renew locally completed subscriptions at period end, applying any scheduled downgrade (Stripe renews itself).';

    /**
     * Gateways whose renewals happen in this application rather than at the
     * provider.
     *
     * @var list<string>
     */
    private const array LOCALLY_RENEWED_GATEWAYS = ['dummy', 'simulated'];

    /**
     * Dummy rows are only renewed (with a fake charge) while the dummy gateway
     * is allowed, which is never the case in production. Simulated payments
     * are opt-in everywhere, so they renew wherever they are selected.
     */
    public function handle(): int
    {
        if (config('settlo.payment_gateway') !== 'simulated' && ! DummyGateway::isAllowed()) {
            $this->warn('The dummy gateway is not allowed in this environment; no subscriptions were renewed.');

            return self::SUCCESS;
        }

        $subscriptions = app(SubscriptionService::class);

        if (! in_array($subscriptions->gateway()->name(), self::LOCALLY_RENEWED_GATEWAYS, true)) {
            $this->info('Renewed 0 subscription(s).');

            return self::SUCCESS;
        }

        $count = 0;

        Subscription::query()
            ->whereIn('gateway', self::LOCALLY_RENEWED_GATEWAYS)
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
