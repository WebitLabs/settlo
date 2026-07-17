<?php

namespace Database\Seeders;

use App\Enums\AiEscalationStatus;
use App\Enums\AiQuestionCategory;
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
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TaxProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
        $maria = $this->seedFirm($entity);
        $this->seedAiConversations($entity, $anna, $maria);
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
