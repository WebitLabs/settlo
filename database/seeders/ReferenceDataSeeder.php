<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Idempotent reference data required in every environment (production too):
 * cantons, fiscal configs, communes, federal brackets, social-insurance rates,
 * VAT config, expense categories and subscription plans.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CantonSeeder::class,
            CommuneSeeder::class,
            FederalTaxBracketSeeder::class,
            SocialInsuranceRateSeeder::class,
            VatConfigSeeder::class,
            ExpenseCategorySeeder::class,
            PlanSeeder::class,
        ]);
    }
}
