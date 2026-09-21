<?php

namespace App\Console\Commands;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Services\Billing\WorkspacePricing;
use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

/**
 * Creates / updates the Stripe catalogue from the active plans: one product
 * per plan (metadata.settlo_plan_code), a monthly and a yearly CHF price
 * (lookup keys settlo_{code}_month / settlo_{code}_year) and the
 * multi-business coupons (the configured ids, by default
 * `settlo-workspace-{percent}`). Stores the price ids on the plans. Idempotent.
 */
class SyncStripePlans extends Command
{
    protected $signature = 'settlo:stripe-sync-plans';

    protected $description = 'Create or update the Stripe products, prices and discount coupons for the active plans.';

    public function handle(WorkspacePricing $pricing): int
    {
        if (blank(config('cashier.secret'))) {
            $this->error('STRIPE_SECRET is not configured.');

            return self::FAILURE;
        }

        $stripe = Cashier::stripe();

        foreach (Plan::where('is_active', true)->orderBy('sort_order')->get() as $plan) {
            $productId = $this->syncProduct($stripe, $plan);

            $plan->forceFill([
                'stripe_product_id' => $productId,
                'stripe_price_monthly_id' => $this->syncPrice($stripe, $plan, $productId, BillingInterval::Month),
                'stripe_price_yearly_id' => $this->syncPrice($stripe, $plan, $productId, BillingInterval::Year),
            ])->save();

            $this->info("{$plan->name}: {$plan->stripe_product_id} · {$plan->stripe_price_monthly_id} · {$plan->stripe_price_yearly_id}");
        }

        foreach (array_keys(array_filter((array) config('settlo.billing.workspace_discounts', []))) as $position) {
            $percent = (int) config("settlo.billing.workspace_discounts.{$position}");
            $couponId = $this->syncCoupon($stripe, (string) $pricing->couponFor($percent), $percent);

            $this->info("Coupon {$percent} %: {$couponId}");
        }

        return self::SUCCESS;
    }

    private function syncProduct(StripeClient $stripe, Plan $plan): string
    {
        $attributes = [
            'name' => "Settlo {$plan->name}",
            'metadata' => ['settlo_plan_code' => $plan->code],
        ];

        if (filled($plan->stripe_product_id)) {
            try {
                return $stripe->products->update($plan->stripe_product_id, [...$attributes, 'active' => true])->id;
            } catch (InvalidRequestException) {
                // The stored product no longer exists — search / create below.
            }
        }

        $existing = $stripe->products->search([
            'query' => "metadata['settlo_plan_code']:'{$plan->code}'",
        ])->data[0] ?? null;

        if ($existing !== null) {
            return $stripe->products->update($existing->id, [...$attributes, 'active' => true])->id;
        }

        return $stripe->products->create($attributes)->id;
    }

    private function syncPrice(StripeClient $stripe, Plan $plan, string $productId, BillingInterval $interval): string
    {
        $lookupKey = "settlo_{$plan->code}_{$interval->value}";
        $amount = (int) bcmul($plan->priceFor($interval), '100', 0);
        $currency = strtolower($plan->currency_code ?? 'chf');

        $existing = $stripe->prices->all(['lookup_keys' => [$lookupKey], 'active' => true, 'limit' => 1])->data[0] ?? null;

        if ($existing !== null
            && $existing->product === $productId
            && $existing->unit_amount === $amount
            && $existing->currency === $currency
            && $existing->recurring?->interval === $interval->value) {
            return $existing->id;
        }

        $price = $stripe->prices->create([
            'product' => $productId,
            'currency' => $currency,
            'unit_amount' => $amount,
            'recurring' => ['interval' => $interval->value],
            'lookup_key' => $lookupKey,
            'transfer_lookup_key' => true,
            'metadata' => ['settlo_plan_code' => $plan->code, 'interval' => $interval->value],
        ]);

        if ($existing !== null) {
            $stripe->prices->update($existing->id, ['active' => false]);
        }

        return $price->id;
    }

    private function syncCoupon(StripeClient $stripe, string $couponId, int $percent): string
    {
        try {
            return $stripe->coupons->retrieve($couponId)->id;
        } catch (InvalidRequestException) {
            return $stripe->coupons->create([
                'id' => $couponId,
                'name' => "Multi-business discount {$percent} %",
                'percent_off' => $percent,
                'duration' => 'forever',
            ])->id;
        }
    }
}
