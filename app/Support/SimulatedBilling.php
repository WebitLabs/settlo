<?php

namespace App\Support;

/**
 * Whether the money moving through the app is real. Every gateway other than
 * Stripe marks subscriptions as paid without charging a card, so any screen
 * that adds those amounts up — the MRR headline, the payments ledger, the
 * owner's own payment history — has to say so rather than present a simulation
 * as collected revenue.
 */
final class SimulatedBilling
{
    /** The only gateway that takes real money. */
    public const string REAL_GATEWAY = 'stripe';

    /**
     * Whether the gateway currently bound settles payments without charging.
     */
    public static function isActive(): bool
    {
        return config('settlo.payment_gateway') !== self::REAL_GATEWAY;
    }

    /**
     * Whether a recorded payment was taken by a simulating gateway. Rows
     * without a gateway predate the column and are treated as simulated.
     */
    public static function isSimulatedPayment(?string $gateway): bool
    {
        return $gateway !== self::REAL_GATEWAY;
    }

    /**
     * The sentence shown above any figure built from simulated payments.
     */
    public static function notice(): string
    {
        return 'Simulated payments: these amounts were never charged to a card and no money was collected.';
    }

    /**
     * A short suffix for a heading or stat label, e.g. "MRR (simulated)".
     */
    public static function label(string $label): string
    {
        return self::isActive() ? $label.' (simulated)' : $label;
    }
}
