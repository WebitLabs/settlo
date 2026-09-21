<?php

namespace App\Console\Commands;

use App\Billing\StripeGateway;
use App\Enums\SubscriptionStatus;
use App\Filament\Personal\Pages\Billing;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

class ExpireTrials extends Command
{
    protected $signature = 'settlo:expire-trials';

    protected $description = 'Lock workspaces whose trial has ended without a paid plan into a read-only state.';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Trialing)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->with(['user', 'businessEntity'])
            ->each(function (Subscription $subscription) use ($subscriptions, &$count) {
                // A trial that was converted at Stripe is kept; the webhook moves it on.
                if (StripeGateway::hasLiveSubscription($subscription)) {
                    return;
                }

                $subscriptions->expire($subscription);
                $this->notifyOwner($subscription);
                $count++;
            });

        $this->info("Expired {$count} trial(s).");

        return self::SUCCESS;
    }

    private function notifyOwner(Subscription $subscription): void
    {
        if ($subscription->user === null) {
            return;
        }

        $name = $subscription->businessEntity?->name ?? 'your business';

        Notification::make()
            ->title("Your trial for {$name} ended")
            ->body('Choose a plan to keep working.')
            ->icon('heroicon-o-clock')
            ->status('warning')
            ->actions([
                Action::make('billing')
                    ->label('Billing')
                    ->url(Billing::getUrl(['workspace' => $subscription->business_entity_id], panel: 'app')),
            ])
            ->sendToDatabase($subscription->user);
    }
}
