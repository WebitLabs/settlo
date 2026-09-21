<?php

namespace App\Filament\Support;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;

/**
 * The short subscription state shown on business cards: "Trial — N days left",
 * "Active", "Payment required", "Expired" or "Cancelled".
 */
final class SubscriptionBadge
{
    /**
     * @return array{label: string, color: string, needsPayment: bool}
     */
    public static function for(?Subscription $subscription): array
    {
        return match ($subscription?->status) {
            SubscriptionStatus::Trialing => [
                'label' => self::trialLabel($subscription),
                'color' => 'info',
                'needsPayment' => false,
            ],
            SubscriptionStatus::Active => ['label' => 'Active', 'color' => 'success', 'needsPayment' => false],
            SubscriptionStatus::PastDue, SubscriptionStatus::Incomplete, null => ['label' => 'Payment required', 'color' => 'danger', 'needsPayment' => true],
            SubscriptionStatus::Expired => ['label' => 'Expired', 'color' => 'danger', 'needsPayment' => true],
            SubscriptionStatus::Cancelled => ['label' => 'Cancelled', 'color' => 'gray', 'needsPayment' => true],
        };
    }

    /**
     * Whole days left in the trial (0 once it has ended), or null without an end date.
     */
    public static function trialDaysLeft(Subscription $subscription): ?int
    {
        if ($subscription->trial_ends_at === null) {
            return null;
        }

        return max(0, (int) ceil(now()->floatDiffInDays($subscription->trial_ends_at, false)));
    }

    private static function trialLabel(Subscription $subscription): string
    {
        $days = self::trialDaysLeft($subscription);

        return match (true) {
            $days === null => 'Trial',
            $days === 1 => 'Trial — 1 day left',
            default => "Trial — {$days} days left",
        };
    }
}
