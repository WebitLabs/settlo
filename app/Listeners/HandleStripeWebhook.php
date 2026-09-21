<?php

namespace App\Listeners;

use App\Models\StripeSubscription;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Arr;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Mirrors Stripe events onto the per-workspace domain subscriptions. Cashier
 * verifies the signature and updates its own tables first; subscription and
 * paid-invoice events arrive as WebhookHandled, failed payments (which Cashier
 * does not handle) as WebhookReceived. A subscription is only touched when it
 * belongs to the Stripe customer of the event.
 */
class HandleStripeWebhook
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;
        $object = (array) Arr::get($payload, 'data.object', []);

        match (Arr::get($payload, 'type')) {
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted' => $this->syncSubscription($object),
            'invoice.payment_succeeded' => $this->recordPayment($object),
            default => null,
        };
    }

    public function handleReceived(WebhookReceived $event): void
    {
        $payload = $event->payload;

        if (Arr::get($payload, 'type') !== 'invoice.payment_failed') {
            return;
        }

        $invoice = (array) Arr::get($payload, 'data.object', []);
        $subscription = $this->subscriptionForInvoice($invoice);

        if ($subscription !== null) {
            $this->subscriptions->markPaymentFailed($subscription);
        }
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function syncSubscription(array $object): void
    {
        $subscription = $this->findSubscription(
            (array) Arr::get($object, 'metadata', []),
            Arr::get($object, 'customer'),
        );

        if ($subscription !== null) {
            $this->subscriptions->syncFromStripe($subscription, $object);
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function recordPayment(array $invoice): void
    {
        $subscription = $this->subscriptionForInvoice($invoice);

        if ($subscription !== null && (int) Arr::get($invoice, 'amount_paid', 0) > 0) {
            $this->subscriptions->recordStripePayment($subscription, $invoice);
        }
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private function subscriptionForInvoice(array $invoice): ?Subscription
    {
        $customer = Arr::get($invoice, 'customer');
        $metadata = (array) Arr::get($invoice, 'parent.subscription_details.metadata', []);

        $subscription = $this->findSubscription($metadata, $customer);

        if ($subscription !== null) {
            return $subscription;
        }

        $stripeId = Arr::get($invoice, 'parent.subscription_details.subscription') ?? Arr::get($invoice, 'subscription');

        if (! is_string($stripeId)) {
            return null;
        }

        $type = StripeSubscription::where('stripe_id', $stripeId)->value('type');

        return $type !== null ? $this->findSubscription(['type' => $type], $customer) : null;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function findSubscription(array $metadata, mixed $customer): ?Subscription
    {
        $subscription = null;

        if (filled($id = Arr::get($metadata, 'settlo_subscription_id'))) {
            $subscription = Subscription::find($id);
        }

        if ($subscription === null && filled($type = Arr::get($metadata, 'type'))) {
            $subscription = Subscription::where('stripe_subscription_type', $type)
                ->whereHas('user', fn ($query) => $query->where('stripe_id', $customer))
                ->first();
        }

        if ($subscription === null || ! is_string($customer) || $subscription->user?->stripe_id !== $customer) {
            return null;
        }

        return $subscription;
    }
}
