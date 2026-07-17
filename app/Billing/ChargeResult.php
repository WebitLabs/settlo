<?php

namespace App\Billing;

/**
 * Outcome of a gateway charge attempt. Gateways return this; the billing
 * service records it as a SubscriptionPayment.
 */
final readonly class ChargeResult
{
    public function __construct(
        public bool $successful,
        public string $reference,
        public string $gateway,
        public ?string $failureReason = null,
    ) {}

    public static function success(string $reference, string $gateway): self
    {
        return new self(true, $reference, $gateway);
    }

    public static function failure(string $reason, string $gateway): self
    {
        return new self(false, '', $gateway, $reason);
    }
}
