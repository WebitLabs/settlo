<?php

namespace App\Services\Billing;

use App\Billing\ChargeResult;
use App\Billing\PaymentGateway;
use App\Billing\QuotaExceededException;
use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Filament\Personal\Pages\Billing;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the per-workspace subscription lifecycle: start (trial for the owner's
 * first workspace only, otherwise payment required), checkout, plan changes,
 * cancellation, local renewals, Stripe webhook syncing, quota metering and
 * expiry. All state transitions are server-side; nothing here trusts client
 * input, and quota spend is atomic.
 */
class SubscriptionService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly WorkspacePricing $pricing,
    ) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    /**
     * Create the subscription of a new workspace. The discount tier is locked
     * now (P5). Only an owner who never had a trial gets one (no card);
     * otherwise the workspace stays locked (Incomplete) until checkout completes.
     */
    public function startWorkspaceSubscription(BusinessEntity $entity, Plan $plan, BillingInterval $interval = BillingInterval::Month): Subscription
    {
        return DB::transaction(fn (): Subscription => $this->startLockedWorkspaceSubscription($entity, $plan, $interval));
    }

    /**
     * The owner row is locked for the rest of the transaction, so two
     * businesses set up at the same moment can never both get the trial.
     */
    private function startLockedWorkspaceSubscription(BusinessEntity $entity, Plan $plan, BillingInterval $interval): Subscription
    {
        /** @var User $owner */
        $owner = User::query()->whereKey($entity->owner_id)->lockForUpdate()->firstOrFail();
        $discount = $this->pricing->discountFor($owner, except: $entity);
        $now = Carbon::now();

        $subscription = Subscription::firstOrNew(['business_entity_id' => $entity->getKey()]);
        $subscription->forceFill([
            'user_id' => $owner->getKey(),
            'business_entity_id' => $entity->getKey(),
            'plan_id' => $plan->getKey(),
            'billing_interval' => $interval,
            'discount_percent' => $discount,
            'unit_price' => $this->pricing->unitPrice($plan, $interval, $discount),
            'gateway' => $this->gateway->name(),
            'human_answers_used' => 0,
            'pending_plan_id' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ]);

        if (! $owner->hasUsedTrial()) {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Trialing,
                'trial_starts_at' => $now,
                'trial_ends_at' => $now->copy()->addDays((int) config('settlo.billing.trial_days', $plan->trial_days)),
                'trial_used' => true,
                'human_answers_quota' => $plan->human_answers_quota,
                'quota_reset_at' => $now->copy()->addMonthNoOverflow()->startOfMonth(),
            ]);

            $owner->forceFill(['trial_used_at' => $now])->save();
        } else {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Incomplete,
                'trial_starts_at' => null,
                'trial_ends_at' => null,
                'human_answers_quota' => 0,
                'quota_reset_at' => null,
            ]);
        }

        $subscription->save();

        return $subscription;
    }

    /**
     * The gateway URL where the owner pays for this workspace.
     */
    public function checkoutUrl(Subscription $subscription, string $successUrl, string $cancelUrl): string
    {
        return $this->gateway->checkoutUrl($subscription, $successUrl, $cancelUrl);
    }

    /**
     * Pick the plan before checkout (trialing, incomplete, expired or
     * cancelled workspaces). The discount is recomputed from the owner's other
     * active workspaces.
     */
    public function choosePlan(Subscription $subscription, Plan $plan, BillingInterval $interval): Subscription
    {
        /** @var User $owner */
        $owner = $subscription->user()->firstOrFail();
        $entity = $subscription->businessEntity;
        $discount = $this->pricing->discountFor($owner, except: $entity);

        $subscription->forceFill([
            'plan_id' => $plan->getKey(),
            'billing_interval' => $interval,
            'discount_percent' => $discount,
            'unit_price' => $this->pricing->unitPrice($plan, $interval, $discount),
            'pending_plan_id' => null,
        ]);

        if ($subscription->status === SubscriptionStatus::Trialing) {
            $subscription->forceFill(['human_answers_quota' => $plan->human_answers_quota]);
        }

        $subscription->save();

        return $subscription;
    }

    /**
     * Change plan and/or interval of a paying workspace, keeping its locked
     * discount. Stripe prorates and the webhook syncs the period; the dummy
     * gateway applies upgrades now (charge) and defers downgrades to the
     * period end.
     */
    public function changePlan(Subscription $subscription, Plan $newPlan, ?BillingInterval $interval = null): Subscription
    {
        $interval ??= $subscription->billing_interval ?? BillingInterval::Month;
        $discount = (int) $subscription->discount_percent;
        $unitPrice = $this->pricing->unitPrice($newPlan, $interval, $discount);

        if ($subscription->gateway === 'stripe') {
            $this->gateway->swap($subscription, $newPlan, $interval);

            $subscription->forceFill([
                'plan_id' => $newPlan->getKey(),
                'billing_interval' => $interval,
                'unit_price' => $unitPrice,
                'human_answers_quota' => $newPlan->human_answers_quota,
                'pending_plan_id' => null,
            ])->save();

            return $subscription;
        }

        $current = $subscription->plan;

        if ($current && bccomp((string) $newPlan->price_monthly, (string) $current->price_monthly, 2) < 0) {
            // Downgrade — defer the plan to period end so the owner keeps what they paid for.
            $subscription->forceFill([
                'pending_plan_id' => $newPlan->getKey(),
                'billing_interval' => $interval,
                'unit_price' => $unitPrice,
            ])->save();

            return $subscription;
        }

        $subscription->forceFill(['billing_interval' => $interval, 'unit_price' => $unitPrice]);

        return $this->activate($subscription, $newPlan);
    }

    /**
     * Pay for a workspace right now, without leaving the application
     * ({@see PaymentGateway::completesCheckoutInstantly()}). Idempotent: the
     * subscription row is locked and a second press of the pay button neither
     * charges again nor moves the billing period. The discount tier stays as
     * it was locked at creation, and the trial columns are never touched (the
     * MRR trial-conversion metric reads them).
     */
    public function payNow(Subscription $subscription, ?Plan $plan = null): Subscription
    {
        DB::transaction(function () use ($subscription, $plan): void {
            /** @var Subscription $locked */
            $locked = Subscription::query()->whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();

            $planId = $plan?->getKey() ?? $locked->plan_id;

            $alreadyPaid = $locked->status === SubscriptionStatus::Active
                && $locked->current_period_end?->isFuture()
                && $locked->plan_id === $planId;

            if ($alreadyPaid) {
                return;
            }

            $this->activate($locked, $plan);
        });

        return $subscription->refresh();
    }

    /**
     * Activate a plan immediately (locally completed gateways): charge and
     * open a new billing period of the subscription's interval. Quota resets
     * to the plan's allowance. The subscription and its payment row are
     * written together, and the payment is keyed on its gateway reference so a
     * retry of the same period can never bill twice.
     */
    public function activate(Subscription $subscription, ?Plan $plan = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $plan): Subscription {
            $plan ??= $subscription->plan;
            $user = $subscription->user;
            $interval = $subscription->billing_interval ?? BillingInterval::Month;
            $unitPrice = $this->pricing->unitPrice($plan, $interval, (int) $subscription->discount_percent);

            $this->gateway->ensureCustomer($user);
            $result = $this->gateway->charge($user, $plan);

            $now = Carbon::now();
            $periodEnd = $interval === BillingInterval::Year
                ? $now->copy()->addYearNoOverflow()
                : $now->copy()->addMonthNoOverflow();

            $subscription->forceFill([
                'plan_id' => $plan->getKey(),
                'unit_price' => $unitPrice,
                'status' => $result->successful ? SubscriptionStatus::Active : SubscriptionStatus::PastDue,
                'current_period_start' => $now,
                'current_period_end' => $periodEnd,
                'human_answers_used' => 0,
                'human_answers_quota' => $plan->human_answers_quota,
                'quota_reset_at' => $now->copy()->addMonthNoOverflow()->startOfMonth(),
                'pending_plan_id' => null,
                'cancel_at_period_end' => false,
                'canceled_at' => null,
                'gateway' => $this->gateway->name(),
            ])->save();

            if ($result->successful) {
                SubscriptionPayment::firstOrCreate([
                    'gateway' => $result->gateway,
                    'gateway_reference' => self::paymentReference($result, $subscription, $now),
                ], [
                    'subscription_id' => $subscription->getKey(),
                    'plan_id' => $plan->getKey(),
                    'amount' => $unitPrice,
                    'currency_code' => $plan->currency_code,
                    'status' => 'paid',
                    'paid_at' => $now,
                    'period_start' => $now,
                    'period_end' => $periodEnd,
                ]);
            }

            return $subscription->refresh();
        });
    }

    /**
     * The reference a payment row is deduplicated on. Stripe hands out its own
     * ids; a locally completed gateway gets one that is deterministic per
     * subscription and billing period, so replaying the same activation adds
     * no second row.
     */
    private static function paymentReference(ChargeResult $result, Subscription $subscription, Carbon $periodStart): string
    {
        if ($result->gateway === 'stripe') {
            return $result->reference;
        }

        return sprintf('%s:%s:%d', $result->gateway, $subscription->getKey(), $periodStart->getTimestamp());
    }

    public function cancel(Subscription $subscription): Subscription
    {
        if ($subscription->gateway === 'stripe') {
            $this->gateway->cancel($subscription);
        }

        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'canceled_at' => Carbon::now(),
        ])->save();

        return $subscription;
    }

    public function resume(Subscription $subscription): Subscription
    {
        if ($subscription->gateway === 'stripe') {
            $this->gateway->resume($subscription);
        }

        $subscription->forceFill([
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ])->save();

        return $subscription;
    }

    /**
     * Mirror a Stripe subscription object onto the domain subscription.
     *
     * @param  array<string, mixed>  $stripeObject
     */
    public function syncFromStripe(Subscription $subscription, array $stripeObject): Subscription
    {
        $firstItem = Arr::get($stripeObject, 'items.data.0', []);
        $periodStart = Arr::get($firstItem, 'current_period_start') ?? Arr::get($stripeObject, 'current_period_start');
        $periodEnd = Arr::get($firstItem, 'current_period_end') ?? Arr::get($stripeObject, 'current_period_end');
        $endedAt = Arr::get($stripeObject, 'ended_at');

        $status = match ((string) Arr::get($stripeObject, 'status')) {
            'trialing' => SubscriptionStatus::Trialing,
            'active' => SubscriptionStatus::Active,
            'past_due', 'unpaid' => SubscriptionStatus::PastDue,
            'incomplete' => SubscriptionStatus::Incomplete,
            'incomplete_expired', 'canceled' => $endedAt !== null && Carbon::createFromTimestamp($endedAt)->isPast()
                ? SubscriptionStatus::Expired
                : SubscriptionStatus::Cancelled,
            default => $subscription->status,
        };

        $attributes = [
            'status' => $status,
            'gateway' => 'stripe',
            'gateway_subscription_id' => Arr::get($stripeObject, 'id', $subscription->gateway_subscription_id),
            'gateway_customer_id' => Arr::get($stripeObject, 'customer', $subscription->gateway_customer_id),
            'stripe_subscription_type' => $subscription->stripe_subscription_type
                ?? Arr::get($stripeObject, 'metadata.type'),
            'cancel_at_period_end' => (bool) Arr::get($stripeObject, 'cancel_at_period_end', false),
            'canceled_at' => ($canceledAt = Arr::get($stripeObject, 'canceled_at')) !== null
                ? Carbon::createFromTimestamp($canceledAt)
                : null,
        ];

        if (($trialEnd = Arr::get($stripeObject, 'trial_end')) !== null) {
            $attributes['trial_ends_at'] = Carbon::createFromTimestamp($trialEnd);
        }

        $newPeriodStart = $periodStart !== null ? Carbon::createFromTimestamp($periodStart) : null;

        if ($newPeriodStart !== null) {
            $attributes['current_period_start'] = $newPeriodStart;
            $attributes['current_period_end'] = $periodEnd !== null ? Carbon::createFromTimestamp($periodEnd) : null;
        }

        $priceId = Arr::get($firstItem, 'price.id');
        $plan = $priceId !== null
            ? Plan::query()
                ->where('stripe_price_monthly_id', $priceId)
                ->orWhere('stripe_price_yearly_id', $priceId)
                ->first()
            : null;

        $plan ??= $subscription->plan;

        if ($priceId !== null && $plan !== null) {
            $interval = $plan->stripe_price_yearly_id === $priceId ? BillingInterval::Year : BillingInterval::Month;

            $attributes['plan_id'] = $plan->getKey();
            $attributes['billing_interval'] = $interval;
            $attributes['unit_price'] = $this->pricing->unitPrice($plan, $interval, (int) $subscription->discount_percent);
            $attributes['pending_plan_id'] = null;
        }

        $periodChanged = $newPeriodStart !== null
            && ($subscription->current_period_start === null || ! $subscription->current_period_start->equalTo($newPeriodStart));

        if ($periodChanged || ($plan !== null && $status->grantsAccess() && ! $subscription->status?->grantsAccess())) {
            $attributes['human_answers_used'] = 0;
            $attributes['human_answers_quota'] = $plan?->human_answers_quota ?? 0;
            $attributes['quota_reset_at'] = Carbon::now()->addMonthNoOverflow()->startOfMonth();
        }

        if (! $status->grantsAccess()) {
            $attributes['human_answers_quota'] = 0;
        }

        $subscription->forceFill($attributes)->save();

        return $subscription;
    }

    /**
     * Record a paid Stripe invoice once (idempotent on the invoice id).
     *
     * @param  array<string, mixed>  $invoice
     */
    public function recordStripePayment(Subscription $subscription, array $invoice): SubscriptionPayment
    {
        $periodStart = Arr::get($invoice, 'lines.data.0.period.start');
        $periodEnd = Arr::get($invoice, 'lines.data.0.period.end');
        $paidAt = Arr::get($invoice, 'status_transitions.paid_at') ?? Arr::get($invoice, 'created');

        $payment = SubscriptionPayment::firstOrCreate([
            'gateway' => 'stripe',
            'gateway_reference' => (string) Arr::get($invoice, 'id'),
        ], [
            'subscription_id' => $subscription->getKey(),
            'plan_id' => $subscription->plan_id,
            'amount' => bcdiv((string) (int) Arr::get($invoice, 'amount_paid', 0), '100', 2),
            'currency_code' => strtoupper((string) Arr::get($invoice, 'currency', 'chf')),
            'status' => 'paid',
            'paid_at' => $paidAt !== null ? Carbon::createFromTimestamp($paidAt) : Carbon::now(),
            'period_start' => $periodStart !== null ? Carbon::createFromTimestamp($periodStart) : null,
            'period_end' => $periodEnd !== null ? Carbon::createFromTimestamp($periodEnd) : null,
        ]);

        if ($subscription->status === SubscriptionStatus::PastDue) {
            $subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
        }

        return $payment;
    }

    /**
     * A renewal payment failed: the workspace stays usable (past due) and the
     * owner is asked to update the payment method.
     */
    public function markPaymentFailed(Subscription $subscription): Subscription
    {
        $subscription->forceFill(['status' => SubscriptionStatus::PastDue])->save();

        $entity = $subscription->businessEntity;
        $owner = $subscription->user;

        if ($owner !== null) {
            $name = $entity?->name ?? 'your business';

            Notification::make()
                ->title("Payment failed for {$name}")
                ->body('Update your payment method to keep your business active.')
                ->icon('heroicon-o-credit-card')
                ->status('danger')
                ->actions([
                    Action::make('billing')
                        ->label('Billing')
                        ->url(Billing::getUrl(['workspace' => $subscription->business_entity_id], panel: 'app')),
                ])
                ->sendToDatabase($owner);
        }

        return $subscription;
    }

    /**
     * Consume one human-answer credit atomically. A row lock plus a
     * conditional update prevents two concurrent escalations from spending the
     * same last credit.
     */
    public function consumeHumanAnswer(Subscription $subscription): void
    {
        DB::transaction(function () use ($subscription) {
            /** @var Subscription $locked */
            $locked = Subscription::whereKey($subscription->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->human_answers_used >= $locked->human_answers_quota) {
                throw QuotaExceededException::humanAnswers();
            }

            $locked->increment('human_answers_used');
        });

        $subscription->refresh();
    }

    /**
     * Monthly quota reset (calendar month, no rollover).
     */
    public function resetQuota(Subscription $subscription): void
    {
        $subscription->forceFill([
            'human_answers_used' => 0,
            'human_answers_quota' => $subscription->grantsAccess() ? ($subscription->plan?->human_answers_quota ?? 0) : 0,
            'quota_reset_at' => Carbon::now()->addMonthNoOverflow()->startOfMonth(),
        ])->save();
    }

    /**
     * End a trial that has run out with no paid plan — lock to read-only.
     */
    public function expire(Subscription $subscription): Subscription
    {
        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();

        return $subscription;
    }

    /**
     * Renew at period end (locally completed gateways): apply any scheduled downgrade,
     * charge, and open the next period.
     */
    public function renew(Subscription $subscription): Subscription
    {
        $plan = $subscription->pendingPlan ?? $subscription->plan;

        if ($subscription->cancel_at_period_end) {
            return $this->expire($subscription);
        }

        return $this->activate($subscription, $plan);
    }
}
