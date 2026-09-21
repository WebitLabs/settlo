<?php

namespace App\Billing;

use RuntimeException;

/**
 * A checkout cannot be started: the workspace is already paid for at the
 * gateway, or the gateway is not set up for the chosen plan / discount (a
 * misconfiguration, which is reported; the owner only sees a generic message).
 */
class CheckoutUnavailableException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $ownerMessage,
        public readonly bool $isMisconfiguration,
    ) {
        parent::__construct($message);
    }

    public static function alreadySubscribed(): self
    {
        $message = 'This business is already paid for. Its status updates as soon as Stripe confirms the payment.';

        return new self($message, $message, isMisconfiguration: false);
    }

    public static function missingPrice(): self
    {
        return new self(
            'The plan has no Stripe price. Run `php artisan settlo:stripe-sync-plans` first.',
            self::unavailableMessage(),
            isMisconfiguration: true,
        );
    }

    public static function missingCoupon(int $discount): self
    {
        return new self(
            "No Stripe coupon is configured for the {$discount} % multi-business discount. Run `php artisan settlo:stripe-sync-plans` first.",
            self::unavailableMessage(),
            isMisconfiguration: true,
        );
    }

    private static function unavailableMessage(): string
    {
        return 'Payments are not available right now. Please try again later or contact support.';
    }
}
