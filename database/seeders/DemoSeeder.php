<?php

namespace Database\Seeders;

use App\Enums\DeductibilityStatus;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MaritalStatus;
use App\Enums\ResidencePermit;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Enums\VatStatus;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\Client;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TaxProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo fixtures for local development and tests only. NEVER runs in production
 * (guarded in DatabaseSeeder) — it ships known-weak credentials.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $zh = Canton::where('code', 'ZH')->first();
        $proPlan = Plan::where('code', 'pro')->first();

        // Superadmin -------------------------------------------------------
        User::updateOrCreate(
            ['email' => 'admin@settlo.ch'],
            [
                'first_name' => 'Sasha',
                'last_name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => UserRole::Superadmin,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
            ],
        );

        // Owner: Anna Müller ----------------------------------------------
        $anna = User::updateOrCreate(
            ['email' => 'anna@test.ch'],
            [
                'first_name' => 'Anna',
                'last_name' => 'Müller',
                'password' => Hash::make('password'),
                'phone' => '+41 79 123 45 67',
                'role' => UserRole::Owner,
                'status' => UserStatus::Active,
                'preferred_language' => 'en',
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
            ],
        );

        $entity = BusinessEntity::updateOrCreate(
            ['owner_id' => $anna->id, 'name' => 'Anna Müller Consulting'],
            [
                'type' => 'sole_proprietorship',
                'uid' => 'CHE-148.830.302',
                'street' => 'Dammstrasse',
                'street_number' => '16',
                'city' => 'Zürich',
                'postal_code' => '8001',
                'canton_id' => $zh?->id,
                'iban' => 'CH02 0900 0000 1638 5793 1',
                'default_currency' => 'CHF',
                'default_payment_term_days' => 30,
                'default_language' => 'en',
                'invoice_number_prefix' => 'INV-',
            ],
        );

        TaxProfile::updateOrCreate(
            ['business_entity_id' => $entity->id],
            [
                'canton_id' => $zh?->id,
                'vat_status' => VatStatus::NotRegistered,
                'estimated_annual_revenue' => 120000,
                'marital_status' => MaritalStatus::Single,
                'number_of_children' => 0,
                'residence_permit' => ResidencePermit::SwissOrCPermit,
                'pillar3a_amount' => 7056,
                'has_pillar2' => false,
                'kirchensteuer' => false,
                'birth_year' => 1992,
            ],
        );

        Subscription::updateOrCreate(
            ['user_id' => $anna->id],
            [
                'plan_id' => $proPlan?->id,
                'status' => SubscriptionStatus::Trialing,
                'trial_starts_at' => now()->subDays(2),
                'trial_ends_at' => now()->addDays(12),
                'human_answers_used' => 0,
                'human_answers_quota' => 1,
                'quota_reset_at' => now()->addMonth()->startOfMonth(),
                'gateway' => 'dummy',
            ],
        );

        $this->seedClientsAndInvoices($entity);
        $this->seedExpenses($entity);
        $this->seedFirm($entity);
    }

    private function seedClientsAndInvoices(BusinessEntity $entity): void
    {
        // Non-draft invoices total CHF 68,400 (revenue YTD) with mixed statuses.
        $invoices = [
            ['Acme AG', 'INV-2026-0001', InvoiceStatus::Paid, 20000, '-90 days', '-60 days'],
            ['Berg & Partner', 'INV-2026-0002', InvoiceStatus::Sent, 15400, '-40 days', '-1 days'], // overdue
            ['Lumen GmbH', 'INV-2026-0003', InvoiceStatus::Paid, 14000, '-70 days', '-40 days'],
            ['Nova Studio', 'INV-2026-0004', InvoiceStatus::Sent, 19000, '-20 days', '+10 days'],
            ['Delta Consulting', 'INV-2026-0005', InvoiceStatus::Draft, 8000, '-5 days', '+25 days'],
        ];

        foreach ($invoices as [$clientName, $number, $status, $subtotal, $issue, $due]) {
            $client = Client::firstOrCreate(
                ['business_entity_id' => $entity->id, 'name' => $clientName],
                [
                    'city' => 'Lausanne',
                    'postal_code' => '1006',
                    'country_code' => 'CH',
                    'default_language' => 'en',
                ],
            );

            $invoice = Invoice::updateOrCreate(
                ['business_entity_id' => $entity->id, 'invoice_number' => $number],
                [
                    'client_id' => $client->id,
                    'status' => $status,
                    'subtotal' => $subtotal,
                    'vat_amount' => 0,
                    'total' => $subtotal,
                    'currency_code' => 'CHF',
                    'issue_date' => now()->modify($issue),
                    'due_date' => now()->modify($due),
                    'language' => 'en',
                    'sent_at' => $status === InvoiceStatus::Draft ? null : now()->modify($issue),
                    'paid_at' => $status === InvoiceStatus::Paid ? now()->modify($due) : null,
                    'paid_amount' => $status === InvoiceStatus::Paid ? $subtotal : 0,
                ],
            );

            $invoice->lineItems()->delete();
            $invoice->lineItems()->create([
                'description' => 'Consulting services',
                'quantity' => 1,
                'unit_price' => $subtotal,
                'vat_rate' => 0,
                'line_total' => $subtotal,
                'sort_order' => 0,
            ]);
        }
    }

    private function seedExpenses(BusinessEntity $entity): void
    {
        $equipment = ExpenseCategory::where('code', 'cat_equipment')->first();
        $travel = ExpenseCategory::where('code', 'cat_travel')->first();
        $meals = ExpenseCategory::where('code', 'cat_meals')->first();

        // Confirmed deductible amounts total CHF 14,200; Digitec left "review needed".
        $rows = [
            ['Digitec', 320.00, 8.1, null, ExpenseStatus::PendingReview, DeductibilityStatus::Uncertain, null],
            ['SBB', 5200.00, 0, $travel, ExpenseStatus::Reviewed, DeductibilityStatus::FullyDeductible, 100],
            ['MacBook / Apple', 6000.00, 8.1, $equipment, ExpenseStatus::Reviewed, DeductibilityStatus::FullyDeductible, 100],
            ['Client lunches', 6000.00, 8.1, $meals, ExpenseStatus::Reviewed, DeductibilityStatus::PartiallyDeductible, 50],
        ];

        foreach ($rows as $i => [$vendor, $amount, $vatRate, $category, $status, $deductibility, $pct]) {
            $net = $vatRate > 0 ? round($amount / (1 + $vatRate / 100), 2) : $amount;

            $entity->expenses()->updateOrCreate(
                ['vendor' => $vendor, 'amount' => $amount],
                [
                    'status' => $status,
                    'vat_amount' => round($amount - $net, 2),
                    'vat_rate' => $vatRate,
                    'net_amount' => $net,
                    'currency_code' => 'CHF',
                    'expense_date' => now()->subDays(30 + $i * 10),
                    'category_id' => $category?->id,
                    'deductibility' => $deductibility,
                    'deductible_pct' => $pct,
                    'deductible_amount' => $pct !== null ? round($amount * $pct / 100, 2) : null,
                ],
            );
        }
    }

    private function seedFirm(BusinessEntity $entity): void
    {
        $firm = AccountingFirm::updateOrCreate(
            ['name' => 'Müller Treuhand AG'],
            [
                'uid' => 'CHE-109.322.551',
                'email' => 'kontakt@mueller-treuhand.ch',
                'city' => 'Zürich',
                'postal_code' => '8001',
                'is_active' => true,
            ],
        );

        $maria = User::updateOrCreate(
            ['email' => 'maria@test.ch'],
            [
                'first_name' => 'Maria',
                'last_name' => 'Schneider',
                'password' => Hash::make('password'),
                'role' => UserRole::Accountant,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
                'onboarding_completed_at' => now(),
            ],
        );

        AccountingFirmMember::updateOrCreate(
            ['accounting_firm_id' => $firm->id, 'user_id' => $maria->id],
            ['is_owner' => true, 'joined_at' => now()],
        );

        AccountantAssignment::updateOrCreate(
            ['accounting_firm_id' => $firm->id, 'business_entity_id' => $entity->id],
            ['accountant_id' => $maria->id, 'assigned_at' => now(), 'revoked_at' => null],
        );
    }
}
