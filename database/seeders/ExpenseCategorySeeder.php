<?php

namespace Database\Seeders;

use App\Enums\DeductibilityStatus;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Swiss business expense categories with default deductibility. Source:
     * Settlo Tax Engine Algorithms v2.0, Section 6 (deductibility rules).
     * [code, name_en, name_de, default pct, DeductibilityStatus, requires_proof,
     * vat_eligible, legal_basis, notes].
     */
    public function run(): void
    {
        $full = DeductibilityStatus::FullyDeductible;
        $partial = DeductibilityStatus::PartiallyDeductible;
        $none = DeductibilityStatus::NotDeductible;

        $categories = [
            ['cat_equipment', 'Office equipment & peripherals', 'Büroausstattung', 100, $full, false, true, 'DBG Art. 27', 'Computer, monitor, keyboard, phone — primarily business use.'],
            ['cat_software', 'Software & subscriptions', 'Software', 100, $full, false, true, 'DBG Art. 27', null],
            ['cat_education', 'Professional education', 'Weiterbildung', 100, $full, false, true, 'DBG Art. 27', 'Current profession only, not career-change training.'],
            ['cat_travel', 'Business travel', 'Geschäftsreisen', 100, $full, false, true, 'DBG Art. 27', 'Train/plane/taxi/hotel for client trips.'],
            ['cat_membership', 'Memberships & fees', 'Mitgliedschaften', 100, $full, false, true, 'DBG Art. 27', null],
            ['cat_marketing', 'Marketing & advertising', 'Marketing', 100, $full, false, true, 'DBG Art. 27', 'Website, Google Ads, LinkedIn Premium.'],
            ['cat_insurance', 'Business insurance', 'Versicherungen', 100, $full, false, true, 'DBG Art. 27', 'Berufshaftpflicht, business interruption.'],
            ['cat_professional', 'Professional services', 'Beratungskosten', 100, $full, false, true, 'DBG Art. 27', 'Treuhänder, business lawyer fees.'],
            ['cat_homeoffice', 'Home office', 'Homeoffice', 100, $full, true, true, 'DBG Art. 27', 'Pro-rata by floor area of rent, utilities, internet.'],
            ['cat_vehicle', 'Vehicle (business use)', 'Fahrzeug', 50, $partial, true, true, 'DBG Art. 27', 'Business km / total km; without a logbook 50% max.'],
            ['cat_meals', 'Business meals', 'Geschäftsessen', 50, $partial, true, true, 'DBG Art. 27 II', 'Client meals — document attendees and business purpose.'],
            ['cat_gifts', 'Client gifts', 'Geschenke', 50, $partial, true, true, 'DBG Art. 27 II', '100% up to CHF 100/recipient/year, 50% above.'],
            ['cat_phone', 'Phone', 'Telefon', 50, $partial, false, true, 'Practice', 'No logbook standard.'],
            ['cat_internet', 'Internet', 'Internet', 50, $partial, false, true, 'Practice', 'If primarily business, up to 80%.'],
            ['cat_clothing', 'Clothing', 'Kleidung', 0, $none, false, false, 'DBG Art. 34', 'Except workwear/uniforms/PPE with no everyday use.'],
            ['cat_personal_meals', 'Personal meals', 'Private Verpflegung', 0, $none, false, false, 'DBG Art. 34', null],
            ['cat_commute', 'Commute', 'Arbeitsweg', 0, $none, false, false, 'DBG Art. 34', 'Commuting to own fixed office not deductible.'],
            ['cat_private_travel', 'Private travel', 'Private Reisen', 0, $none, false, false, 'DBG Art. 34', null],
            ['cat_fines', 'Fines & penalties', 'Bussen', 0, $none, false, false, 'Practice', 'Parking fines, late fees, tax penalties.'],
        ];

        foreach ($categories as $i => [$code, $nameEn, $nameDe, $pct, $status, $proof, $vat, $legal, $notes]) {
            ExpenseCategory::updateOrCreate(
                ['code' => $code],
                [
                    'name_en' => $nameEn,
                    'name_de' => $nameDe,
                    'name_fr' => $nameEn,
                    'name_it' => $nameEn,
                    'default_deductibility' => $status,
                    'default_deductible_pct' => $pct,
                    'requires_proof' => $proof,
                    'vat_eligible' => $vat,
                    'legal_basis' => $legal,
                    'notes' => $notes,
                    'is_active' => true,
                    'sort_order' => $i,
                ],
            );
        }
    }
}
