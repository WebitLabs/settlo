<?php

namespace App\Models;

use Laravel\Cashier\SubscriptionItem;

/**
 * Cashier's local mirror of a Stripe subscription item. The foreign key is
 * derived from {@see StripeSubscription} (`stripe_subscription_id`).
 */
class StripeSubscriptionItem extends SubscriptionItem
{
    protected $table = 'stripe_subscription_items';
}
