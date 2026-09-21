<?php

namespace App\Models;

use Laravel\Cashier\Subscription;

/**
 * Cashier's local mirror of a Stripe subscription. Renamed so it does not
 * collide with the domain `subscriptions` table ({@see \App\Models\Subscription}).
 */
class StripeSubscription extends Subscription
{
    protected $table = 'stripe_subscriptions';
}
