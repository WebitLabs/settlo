<?php

namespace Database\Seeders;

use App\Enums\AiEscalationStatus;
use App\Enums\AiQuestionCategory;
use App\Enums\BillingInterval;
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
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\Canton;
use App\Models\Client;
use App\Models\Commune;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Tax\TaxEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Demo fixtures. In local and testing they carry the fixed weak password
 * ("password"); anywhere else — where they only run behind the explicit
 * `settlo.demo.seed_outside_local` flag — every account gets a strong random
 * password, printed once so it can be captured (see {@see reportCredentials()}).
 */
class DemoSeeder extends Seeder
{
    /**
     * Length of the generated password used outside local/testing.
     *
     * Letters and digits only: these are handed to testers in a document and
     * retyped or copied from a PDF, where punctuation invites transcription
     * errors and breaks on a copy that drops a character. Twenty alphanumeric
     * characters still carry far more entropy than a demo account needs.
     */
    private const int GENERATED_PASSWORD_LENGTH = 20;

    /**
     * The demo credentials created by this run, keyed by email.
     *
     * @var array<string, string>
     */
    private array $credentials = [];

    public function run(): void
    {
        $zh = Canton::where('code', 'ZH')->first();
        $zurich = Commune::where('bfs_number', '261')->first();
        $proPlan = Plan::where('code', 'pro')->first();
        $soloPlan = Plan::where('code', 'solo')->first() ?? $proPlan;

        // Superadmin -------------------------------------------------------
        User::updateOrCreate(
            ['email' => 'admin@settlo.ch'],
            [
                'first_name' => 'Sasha',
                'last_name' => 'Admin',
                'password' => $this->passwordFor('admin@settlo.ch'),
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
                'password' => $this->passwordFor('anna@test.ch'),
                'phone' => '+41 79 123 45 67',
                'phone_country' => 'CH',
                'street' => 'Seefeldstrasse',
                'street_number' => '12',
                'postal_code' => '8008',
                'city' => 'Zürich',
                'country_code' => 'CH',
                'canton_id' => $zh?->id,
                'commune_id' => $zurich?->id,
                'role' => UserRole::Owner,
                'status' => UserStatus::Active,
                'preferred_language' => 'en',
                'email_verified_at' => now(),
                'terms_accepted_at' => now(),
                'privacy_acknowledged_at' => now(),
                'terms_version' => '2026-09',
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
                'vat_status' => VatStatus::NotRegistered,
                'estimated_annual_revenue' => 120000,
                'iban' => 'CH02 0900 0000 1638 5793 1',
                'default_currency' => 'CHF',
                'default_payment_term_days' => 30,
                'default_language' => 'en',
                'invoice_number_prefix' => 'INV-',
            ],
        );

        $anna->forceFill(['last_business_entity_id' => $entity->id])->save();

        $taxProfile = TaxProfile::firstOrNew(['user_id' => $anna->id]);
        $taxProfile->fill([
            'canton_id' => $zh?->id,
            'marital_status' => MaritalStatus::Single,
            'number_of_children' => 0,
            'residence_permit' => ResidencePermit::SwissCitizen,
            'pillar3a_amount' => 7056,
            'has_pillar2' => false,
            'kirchensteuer' => false,
            'birth_year' => 1992,
        ]);
        $taxProfile->forceFill(['user_id' => $anna->id])->save();

        Subscription::updateOrCreate(
            ['business_entity_id' => $entity->id],
            [
                'user_id' => $anna->id,
                'plan_id' => $proPlan?->id,
                'billing_interval' => BillingInterval::Month,
                'discount_percent' => 0,
                'unit_price' => $proPlan?->price_monthly,
                'trial_used' => true,
                'status' => SubscriptionStatus::Trialing,
                'trial_starts_at' => now()->subDays(2),
                'trial_ends_at' => now()->addDays(12),
                'human_answers_used' => 0,
                'human_answers_quota' => 1,
                'quota_reset_at' => now()->addMonth()->startOfMonth(),
                'gateway' => 'dummy',
            ],
        );

        $anna->forceFill(['trial_used_at' => $anna->trial_used_at ?? now()->subDays(2)])->save();

        $this->seedClientsAndInvoices($entity);
        $this->seedExpenses($entity);
        $maria = $this->seedFirm($entity);
        $this->seedAiConversations($entity, $anna, $maria);
        $this->seedSecondBusiness($anna, $zh, $soloPlan);
        $this->seedTaxEstimation($anna);
        $this->reportCredentials();
    }

    /**
     * A second workspace for the same owner, so the multi-business structure —
     * separate books, its own subscription and the 20 % second-workspace
     * discount — is visible in the demo.
     *
     * It is deliberately still empty of issued invoices: the owner's personal
     * tax is split across their sole proprietorships by net income, and a
     * second earning business would change the canonical figures of the first.
     */
    private function seedSecondBusiness(User $owner, ?Canton $canton, ?Plan $plan): BusinessEntity
    {
        $entity = BusinessEntity::updateOrCreate(
            ['owner_id' => $owner->id, 'name' => 'Müller Fotografie'],
            [
                'type' => 'sole_proprietorship',
                'uid' => 'CHE-372.114.869',
                'street' => 'Langstrasse',
                'street_number' => '94',
                'city' => 'Zürich',
                'postal_code' => '8004',
                'canton_id' => $canton?->id,
                'vat_status' => VatStatus::NotRegistered,
                'estimated_annual_revenue' => 24000,
                'iban' => 'CH56 0483 5012 3456 7800 9',
                'default_currency' => 'CHF',
                'default_payment_term_days' => 20,
                'default_language' => 'de',
                'invoice_number_prefix' => 'FOTO-',
            ],
        );

        $discount = (int) (config('settlo.billing.workspace_discounts')[2] ?? 0);
        $price = $plan?->price_monthly !== null
            ? round((float) $plan->price_monthly * (100 - $discount) / 100, 2)
            : null;

        Subscription::updateOrCreate(
            ['business_entity_id' => $entity->id],
            [
                'user_id' => $owner->id,
                'plan_id' => $plan?->id,
                'billing_interval' => BillingInterval::Month,
                'discount_percent' => $discount,
                'unit_price' => $price,
                'trial_used' => true,
                'status' => SubscriptionStatus::Active,
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->startOfMonth()->addMonth(),
                'human_answers_used' => 0,
                'human_answers_quota' => 0,
                'quota_reset_at' => now()->addMonth()->startOfMonth(),
                'gateway' => 'simulated',
            ],
        );

        $client = Client::firstOrCreate(
            ['business_entity_id' => $entity->id, 'name' => 'Hochzeit Keller'],
            [
                'city' => 'Winterthur',
                'postal_code' => '8400',
                'country_code' => 'CH',
                'default_language' => 'de',
            ],
        );

        $invoice = Invoice::updateOrCreate(
            ['business_entity_id' => $entity->id, 'invoice_number' => 'FOTO-2026-0001'],
            [
                'client_id' => $client->id,
                'status' => InvoiceStatus::Draft,
                'subtotal' => 2400,
                'vat_amount' => 0,
                'total' => 2400,
                'currency_code' => 'CHF',
                'issue_date' => now(),
                'due_date' => now()->addDays(20),
                'language' => 'de',
                'paid_amount' => 0,
            ],
        );

        $invoice->lineItems()->delete();
        $invoice->lineItems()->create([
            'description' => 'Hochzeitsreportage',
            'quantity' => 1,
            'unit_price' => 2400,
            'vat_rate' => 0,
            'line_total' => 2400,
            'sort_order' => 0,
        ]);

        return $entity;
    }

    /**
     * Run the tax engine once so the demo opens with a populated estimate
     * instead of empty tax widgets.
     */
    private function seedTaxEstimation(User $owner): void
    {
        app(TaxEngine::class)->estimateAllFor($owner);
    }

    /**
     * The password a demo account is created with: the fixed local credential
     * in local/testing, a strong generated one anywhere else. Generated
     * passwords are kept for {@see reportCredentials()} and never reused
     * between accounts.
     */
    private function passwordFor(string $email): string
    {
        if (app()->environment(['local', 'testing'])) {
            return Hash::make((string) config('settlo.demo.local_password', 'password'));
        }

        $password = Str::password(self::GENERATED_PASSWORD_LENGTH, symbols: false);
        $this->credentials[$email] = $password;

        return Hash::make($password);
    }

    /**
     * Print the generated demo credentials once — to the console when there is
     * one, and to the log so a serverless deploy can still capture them. They
     * cannot be recovered afterwards: re-run the seeder to get new ones.
     */
    private function reportCredentials(): void
    {
        if ($this->credentials === []) {
            return;
        }

        $lines = ['Demo accounts created with generated passwords (shown once):'];

        foreach ($this->credentials as $email => $password) {
            $lines[] = "  {$email}  {$password}";
        }

        $message = implode(PHP_EOL, $lines);

        $this->command?->warn($message);
        Log::warning($message);

        $this->credentials = [];
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

    private function seedFirm(BusinessEntity $entity): User
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
                'password' => $this->passwordFor('maria@test.ch'),
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

        return $maria;
    }

    /**
     * Verbatim simulated accountant answer from the backlog (SETTLO-20), reused
     * for the one pre-answered escalation in the demo data.
     */
    private const MARIA_ANSWER = "Settlo AI's answer is correct. I'd add one practical note: if any single invoice exceeds CHF 100,000 on its own, that triggers mandatory VAT registration immediately, regardless of your YTD total. I recommend starting the ESTV application at least 6 weeks before your target start date and can assist if needed. — Maria Schneider";

    /**
     * Four demo Ask Settlo conversations with realistic Swiss-tax history, one of
     * which carries an already-answered accountant escalation. All ownership and
     * assistant-metadata columns are set server-side via forceFill — never
     * mass-assigned. Conversations are keyed by title so re-seeding is safe.
     *
     * The Pro subscription's human-answer credit is deliberately left unspent so
     * the live escalate → answered flow can still be demonstrated end to end; the
     * seeded escalation stands on its own as historical data.
     */
    private function seedAiConversations(BusinessEntity $entity, User $owner, User $accountant): void
    {
        $firmId = AccountantAssignment::query()
            ->where('business_entity_id', $entity->id)
            ->whereNull('revoked_at')
            ->value('accounting_firm_id');

        $model = (string) config('settlo.anthropic.model', 'claude-sonnet-4-20250514');

        /** @var list<array{age: Carbon, category: AiQuestionCategory, question: string, answer: string, escalate: bool}> $threads */
        $threads = [
            [
                'age' => now()->subDays(5),
                'category' => AiQuestionCategory::VatQuestion,
                'question' => 'Do I need to register for VAT?',
                'answer' => "Based on your current figures, not yet. VAT registration in Switzerland becomes mandatory once your worldwide turnover from taxable supplies reaches CHF 100,000 over a 12-month period. Your revenue year-to-date is CHF 68,400 — roughly 68% of the threshold — so you are still comfortably under it.\n\nA few things to keep in mind:\n• The threshold is based on turnover, not profit.\n• Once you cross CHF 100,000 you must register with the ESTV (Federal Tax Administration) and start charging VAT.\n• You may register voluntarily below the threshold, which lets you reclaim input VAT — often worthwhile if most of your clients are VAT-registered businesses.\n\nGiven your trajectory it is worth tracking monthly so you can register in good time if you approach the limit. Based on Swiss tax law · Verify for your specific situation.",
                'escalate' => true,
            ],
            [
                'age' => now()->subHours(3),
                'category' => AiQuestionCategory::AhvIvEo,
                'question' => 'How is AHV calculated for the self-employed?',
                'answer' => "As a sole proprietor you pay AHV/IV/EO contributions on your net self-employment income (profit), not on turnover. The full rate for the self-employed is 10.0% (8.1% AHV + 1.4% IV + 0.5% EO) once your annual income reaches roughly CHF 58,800.\n\nBelow that, a declining sliding scale applies, down to a minimum contribution of a few hundred francs per year for very low incomes. Your cantonal Ausgleichskasse (compensation office) bills provisional instalments during the year and reconciles them once your final assessment is issued.\n\nUnlike an employee you carry the full contribution yourself — there is no employer to split it with — but AHV contributions are deductible for income-tax purposes. Based on Swiss tax law · Verify for your specific situation.",
                'escalate' => false,
            ],
            [
                'age' => now()->subDays(2),
                'category' => AiQuestionCategory::TaxDeduction,
                'question' => 'Can I deduct home office costs?',
                'answer' => "Yes, provided you genuinely use part of your home mainly for the business and have no other suitable workspace. The deduction is proportional: take the share of floor area used for work and apply it to rent (or the imputed rental value if you own) plus incidental costs such as heating and electricity.\n\nFor example, if a dedicated office is about 15% of your dwelling's area, roughly 15% of the qualifying housing costs can be claimed. A business share of internet and phone is deductible too. Keep a short record of the area calculation and the underlying bills in case the tax office asks.\n\nMixed-use rooms are viewed critically, so a clearly separated, business-first space is much easier to defend. Based on Swiss tax law · Verify for your specific situation.",
                'escalate' => false,
            ],
            [
                'age' => now()->subDays(18),
                'category' => AiQuestionCategory::IncomeTax,
                'question' => 'What is the Pillar 3a maximum for me?',
                'answer' => "It depends on whether you are affiliated with a pension fund (2nd pillar). As a self-employed person without a Pillar 2 you may contribute up to 20% of your net self-employment income into Pillar 3a, capped at CHF 35,280. If you do have a 2nd pillar, the lower 'small' limit of CHF 7,056 applies instead.\n\nContributions are fully deductible from taxable income, which makes 3a one of the most effective tax-saving tools available to the self-employed. You currently record CHF 7,056 per year, so if you are not in a pension fund there may be substantial additional room up to the 20% / CHF 35,280 ceiling.\n\nThe payment must reach your 3a account by 31 December to count for that tax year. Based on Swiss tax law · Verify for your specific situation.",
                'escalate' => false,
            ],
        ];

        foreach ($threads as $thread) {
            $this->seedConversation($entity, $owner, $accountant, $firmId, $model, $thread);
        }
    }

    /**
     * @param  array{age: Carbon, category: AiQuestionCategory, question: string, answer: string, escalate: bool}  $thread
     */
    private function seedConversation(BusinessEntity $entity, User $owner, User $accountant, ?string $firmId, string $model, array $thread): void
    {
        $timestamp = $thread['age']->copy();

        $conversation = AiConversation::firstOrNew([
            'user_id' => $owner->id,
            'business_entity_id' => $entity->id,
            'title' => Str::limit(trim($thread['question']), 50, ''),
        ]);

        $conversation->forceFill([
            'user_id' => $owner->id,
            'business_entity_id' => $entity->id,
            'title' => Str::limit(trim($thread['question']), 50, ''),
            'created_at' => $timestamp,
            'updated_at' => $timestamp->copy()->addMinutes(2),
        ])->save();

        // Re-seeding safe: drop any prior turns so the canned history is exact.
        $conversation->escalations()->delete();
        $conversation->messages()->delete();

        $userMessage = new AiMessage;
        $userMessage->forceFill([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $thread['question'],
            'category' => $thread['category'],
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->save();

        $assistantMessage = new AiMessage;
        $assistantMessage->forceFill([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $thread['answer'],
            'category' => $thread['category'],
            'model_used' => $model,
            'confidence' => 0.90,
            'tokens_used' => 640,
            'processing_ms' => 1800,
            'context_snapshot' => [
                'canton_code' => 'ZH',
                'revenue_ytd' => 68400,
                'vat_status_label' => 'Not registered',
            ],
            'created_at' => $timestamp->copy()->addMinutes(2),
            'updated_at' => $timestamp->copy()->addMinutes(2),
        ])->save();

        if ($thread['escalate']) {
            $this->seedEscalation($conversation, $assistantMessage, $owner, $accountant, $firmId, $thread, $timestamp);
        }
    }

    /**
     * @param  array{age: Carbon, category: AiQuestionCategory, question: string, answer: string, escalate: bool}  $thread
     */
    private function seedEscalation(AiConversation $conversation, AiMessage $assistantMessage, User $owner, User $accountant, ?string $firmId, array $thread, Carbon $timestamp): void
    {
        $answeredAt = $timestamp->copy()->addHours(3);

        $escalation = new AiEscalation;
        $escalation->forceFill([
            'conversation_id' => $conversation->id,
            'message_id' => $assistantMessage->id,
            'user_id' => $owner->id,
            'accounting_firm_id' => $firmId,
            'accountant_id' => $accountant->id,
            'category' => $thread['category'],
            'user_question' => $thread['question'],
            'ai_answer' => $thread['answer'],
            'status' => AiEscalationStatus::Answered->value,
            'accountant_answer' => self::MARIA_ANSWER,
            'answered_at' => $answeredAt,
            'sla_deadline' => $timestamp->copy()->addDay(),
            'sla_breached' => false,
            'created_at' => $timestamp->copy()->addMinutes(3),
            'updated_at' => $answeredAt,
        ])->save();

        $conversation->forceFill(['updated_at' => $answeredAt])->save();
    }
}
