<?php

namespace Database\Seeders;

use App\Enums\PlanFeature;
use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * The three Settlo subscription tiers. `features` drives server-side
     * gating; `marketing_features` is the display-only bullet list on the plan
     * cards (may list claims not yet enforced, e.g. year-end export).
     */
    public function run(): void
    {
        $plans = [
            [
                'code' => 'solo',
                'name' => 'Solo',
                'price_monthly' => 19,
                'trial_days' => 14,
                'human_answers_quota' => 0,
                'features' => [
                    PlanFeature::VatForm300->value,
                ],
                'marketing_features' => [
                    'Swiss QR invoicing',
                    'Expense tracking + OCR',
                    'AI assistant',
                    'VAT threshold tracker',
                    'VAT declaration (Form 300)',
                ],
                'sort_order' => 1,
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'price_monthly' => 49,
                'trial_days' => 14,
                'human_answers_quota' => 1,
                'features' => [
                    PlanFeature::TaxEngine->value,
                    PlanFeature::AccountantAccess->value,
                    PlanFeature::YearEndExport->value,
                    PlanFeature::VatForm300->value,
                ],
                'marketing_features' => [
                    'Everything in Solo',
                    'Canton-aware tax engine',
                    'AHV/IV/EO calculation',
                    '1 accountant answer / month',
                    'Year-end export',
                ],
                'sort_order' => 2,
            ],
            [
                'code' => 'confidence',
                'name' => 'Confidence',
                'price_monthly' => 99,
                'trial_days' => 14,
                'human_answers_quota' => 3,
                'features' => [
                    PlanFeature::TaxEngine->value,
                    PlanFeature::AccountantAccess->value,
                    PlanFeature::YearEndExport->value,
                    PlanFeature::VatForm300->value,
                    PlanFeature::AnnualReview->value,
                    PlanFeature::PriorityResponse->value,
                ],
                'marketing_features' => [
                    'Everything in Pro',
                    '3 accountant answers / month',
                    'Annual tax return review',
                    'Priority accountant response',
                ],
                'sort_order' => 3,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['code' => $plan['code']], $plan);
        }
    }
}
