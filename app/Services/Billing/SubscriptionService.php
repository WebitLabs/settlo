<?php

namespace App\Services\Billing;

use App\Billing\PaymentGateway;
use App\Billing\QuotaExceededException;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the subscription lifecycle: trial start, plan changes (upgrade
 * immediate / downgrade at period end), cancellation, renewal, quota metering
 * and expiry. All state transitions are server-side; nothing here trusts
 * client input, and quota spend is atomic.
 */
class SubscriptionService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * Start a 14-day (plan-defined) trial with the chosen plan. The trial
     * grants full quota for the plan immediately, no charge, no card.
     */
    public function startTrial(User $user, Plan $plan): Subscription
    {
        $now = Carbon::now();

        $subscription = Subscription::firstOrNew(['user_id' => $user->getKey()]);
        $subscription->forceFill([
            'plan_id' => $plan->getKey(),
            'status' => SubscriptionStatus::Trialing,
            'trial_starts_at' => $now,
            'trial_ends_at' => $now->copy()->addDays($plan->trial_days),
            'trial_used' => true,
            'human_answers_used' => 0,
            'human_answers_quota' => $plan->human_answers_quota,
            'quota_reset_at' => $now->copy()->addMonthNoOverflow()->startOfMonth(),
            'gateway' => $this->gateway->name(),
            'pending_plan_id' => null,
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ])->save();

        return $subscription;
    }

    /**
     * Change plan. Upgrades take effect immediately (charge now); downgrades
     * are scheduled for the end of the current period.
     */
    public function changePlan(Subscription $subscription, Plan $newPlan): Subscription
    {
        $current = $subscription->plan;

        if ($current && (float) $newPlan->price_monthly < (float) $current->price_monthly) {
            // Downgrade — defer to period end so the user keeps what they paid for.
            $subscription->forceFill(['pending_plan_id' => $newPlan->getKey()])->save();

            return $subscription;
        }

        // Upgrade or same-price switch — apply now and charge for the new period.
        return $this->activate($subscription, $newPlan);
    }

    /**
     * Activate a plan immediately: charge via the gateway and open a new
     * billing period. Quota resets to the new plan's allowance.
     */
    public function activate(Subscription $subscription, Plan $plan): Subscription
    {
        $user = $subscription->user;
        $this->gateway->ensureCustomer($user);
        $result = $this->gateway->charge($user, $plan);

        $now = Carbon::now();
        $periodEnd = $now->copy()->addMonthNoOverflow();

        $subscription->forceFill([
            'plan_id' => $plan->getKey(),
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
            SubscriptionPayment::create([
                'subscription_id' => $subscription->getKey(),
                'plan_id' => $plan->getKey(),
                'amount' => $plan->price_monthly,
                'currency_code' => $plan->currency_code,
                'status' => 'paid',
                'gateway' => $result->gateway,
                'gateway_reference' => $result->reference,
                'paid_at' => $now,
                'period_start' => $now,
                'period_end' => $periodEnd,
            ]);
        }

        return $subscription->refresh();
    }

    public function cancel(Subscription $subscription): Subscription
    {
        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'canceled_at' => Carbon::now(),
        ])->save();

        return $subscription;
    }

    public function resume(Subscription $subscription): Subscription
    {
        $subscription->forceFill([
            'cancel_at_period_end' => false,
            'canceled_at' => null,
        ])->save();

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
            'human_answers_quota' => $subscription->plan?->human_answers_quota ?? 0,
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
     * Renew at period end: apply any scheduled downgrade, charge, and open the
     * next period.
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
