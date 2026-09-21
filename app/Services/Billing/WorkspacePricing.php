<?php

namespace App\Services\Billing;

use App\Billing\CheckoutUnavailableException;
use App\Enums\BillingInterval;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\User;

/**
 * Per-workspace pricing: the multi-business discount tier (locked when a
 * workspace subscription is created), discounted unit prices (BCMath) and the
 * human-readable price line.
 */
class WorkspacePricing
{
    /**
     * The discount (%) for a new workspace subscription of this owner. The
     * position is the number of other active subscriptions + 1; positions past
     * the last configured tier use the last tier.
     */
    public function discountFor(User $user, ?BusinessEntity $except = null): int
    {
        $position = $user->activeWorkspaceSubscriptionCount($except) + 1;

        /** @var array<int, int> $tiers */
        $tiers = config('settlo.billing.workspace_discounts', [1 => 0]);
        ksort($tiers);

        $discount = 0;

        foreach ($tiers as $tierPosition => $tierDiscount) {
            if ($position >= $tierPosition) {
                $discount = (int) $tierDiscount;
            }
        }

        return $discount;
    }

    /**
     * The price per billing period after the discount, rounded to 2 decimals.
     */
    public function unitPrice(Plan $plan, BillingInterval $interval, int $discount): string
    {
        $list = $plan->priceFor($interval);
        $factor = bcdiv((string) (100 - $discount), '100', 6);

        return $this->round(bcmul($list, $factor, 6));
    }

    /**
     * e.g. "CHF 49 / month", "CHF 490 / year · 2 months free",
     * "CHF 39.20 / month · 20 % multi-business discount".
     */
    public function describe(Plan $plan, BillingInterval $interval, int $discount): string
    {
        $parts = [sprintf(
            '%s %s / %s',
            $plan->currency_code ?? 'CHF',
            $this->formatAmount($this->unitPrice($plan, $interval, $discount)),
            $interval->value,
        )];

        if ($interval === BillingInterval::Year) {
            $parts[] = '2 months free';
        }

        if ($discount > 0) {
            $parts[] = "{$discount} % multi-business discount";
        }

        return implode(' · ', $parts);
    }

    /**
     * The Stripe coupon id for a discount (%): the configured id, or the
     * `settlo-workspace-{n}` id that `settlo:stripe-sync-plans` creates for
     * every configured discount tier. A discount without a coupon throws, so
     * Stripe never charges the full price while the app shows the discount.
     *
     * @throws CheckoutUnavailableException
     */
    public function couponFor(int $discount): ?string
    {
        if ($discount <= 0) {
            return null;
        }

        $coupon = config("settlo.billing.stripe_coupons.{$discount}");

        if (filled($coupon)) {
            return (string) $coupon;
        }

        $tiers = array_map(intval(...), (array) config('settlo.billing.workspace_discounts', []));

        if (! in_array($discount, $tiers, true)) {
            throw CheckoutUnavailableException::missingCoupon($discount);
        }

        return self::defaultCouponId($discount);
    }

    /**
     * The coupon id `settlo:stripe-sync-plans` creates for a discount (%).
     */
    public static function defaultCouponId(int $discount): string
    {
        return "settlo-workspace-{$discount}";
    }

    /**
     * Whole amounts without decimals, otherwise two decimals.
     */
    public function formatAmount(string $amount): string
    {
        return bccomp($amount, bcadd($amount, '0', 0), 2) === 0
            ? bcadd($amount, '0', 0)
            : bcadd($amount, '0', 2);
    }

    private function round(string $value): string
    {
        $offset = bccomp($value, '0', 6) >= 0 ? '0.005' : '-0.005';

        return bcadd(bcadd($value, $offset, 6), '0', 2);
    }
}
