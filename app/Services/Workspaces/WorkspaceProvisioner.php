<?php

namespace App\Services\Workspaces;

use App\Enums\BillingInterval;
use App\Enums\Language;
use App\Enums\VatStatus;
use App\Jobs\RecalculatePersonalTaxEstimation;
use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\Plan;
use App\Models\User;
use App\Rules\ValidIban;
use App\Services\Billing\SubscriptionService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates a business workspace in one transaction: the business itself, its
 * default bank account (when an IBAN was given) and its subscription (trial
 * for the owner's first workspace, otherwise awaiting checkout). Afterwards the
 * consolidated personal tax estimate and every workspace's share are refreshed,
 * since a new sole proprietorship changes all shares.
 */
class WorkspaceProvisioner
{
    /**
     * Business attributes taken from the profile step.
     *
     * @var list<string>
     */
    private const array BUSINESS_ATTRIBUTES = [
        'name', 'legal_name', 'type', 'uid', 'street', 'street_number', 'postal_code',
        'city', 'vat_status', 'mwst_number', 'estimated_annual_revenue',
    ];

    /**
     * Business attributes taken from the invoicing step.
     *
     * @var list<string>
     */
    private const array INVOICING_ATTRIBUTES = [
        'default_payment_term_days', 'default_language', 'invoice_number_prefix', 'default_invoice_notes',
    ];

    public function __construct(private readonly SubscriptionService $subscriptions) {}

    /**
     * @param  array<string, mixed>  $business  Business profile state.
     * @param  array<string, mixed>|null  $invoicing  Invoicing state, or null when the step was skipped.
     * @param  array{plan_id?: string|null, billing_interval?: mixed}|null  $plan  Plan choice, or null for Pro monthly.
     */
    public function create(User $user, array $business, ?array $invoicing = null, ?array $plan = null): BusinessEntity
    {
        $entity = DB::transaction(function () use ($user, $business, $invoicing, $plan): BusinessEntity {
            $iban = ValidIban::normalize((string) ($invoicing['iban'] ?? ''));

            $entity = new BusinessEntity;
            $entity->fill([
                ...Arr::only($business, self::BUSINESS_ATTRIBUTES),
                ...Arr::only($invoicing ?? $this->defaultInvoicing($user), self::INVOICING_ATTRIBUTES),
            ]);

            $entity->vat_status ??= VatStatus::NotRegistered;

            if (! $entity->vat_status->isRegistered()) {
                $entity->mwst_number = null;
            }

            $entity->forceFill([
                'owner_id' => $user->getKey(),
                'canton_id' => $business['canton_id'] ?? null,
                'iban' => $iban !== '' ? $iban : null,
            ]);
            $entity->save();

            if ($iban !== '') {
                $bankAccount = new BankAccount;
                $bankAccount->fill([
                    'bank_name' => filled($invoicing['bank_name'] ?? null) ? $invoicing['bank_name'] : 'Primary account',
                    'account_name' => $entity->name,
                    'currency_code' => $entity->default_currency ?? 'CHF',
                ]);
                $bankAccount->forceFill([
                    'business_entity_id' => $entity->getKey(),
                    'iban' => $iban,
                    'is_default' => true,
                ])->save();
            }

            $this->subscriptions->startWorkspaceSubscription(
                $entity,
                $this->planFrom($plan),
                self::intervalFrom($plan['billing_interval'] ?? null),
            );

            $user->forceFill([
                'onboarding_completed_at' => $user->onboarding_completed_at ?? now(),
                'last_business_entity_id' => $entity->getKey(),
            ])->save();

            return $entity;
        });

        rescue(function () use ($user): void {
            RecalculatePersonalTaxEstimation::dispatch($user->getKey());
        });

        return $entity;
    }

    /**
     * Invoicing defaults for a skipped invoicing step.
     *
     * @return array<string, mixed>
     */
    public function defaultInvoicing(User $user): array
    {
        return [
            'default_payment_term_days' => 30,
            'default_language' => $user->preferred_language ?: Language::English->value,
            'invoice_number_prefix' => 'INV-',
        ];
    }

    /**
     * The chosen active plan, or Pro when none (or an inactive one) was chosen.
     *
     * @param  array{plan_id?: string|null}|null  $plan
     */
    public function planFrom(?array $plan): Plan
    {
        $chosen = filled($plan['plan_id'] ?? null)
            ? Plan::where('is_active', true)->find($plan['plan_id'])
            : null;

        return $chosen ?? Plan::where('code', 'pro')->firstOrFail();
    }

    private static function intervalFrom(mixed $state): BillingInterval
    {
        if ($state instanceof BillingInterval) {
            return $state;
        }

        return BillingInterval::tryFrom((string) $state) ?? BillingInterval::Month;
    }
}
