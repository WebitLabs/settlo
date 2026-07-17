<?php

namespace Database\Seeders;

use App\Models\SocialInsuranceRate;
use Illuminate\Database\Seeder;

class SocialInsuranceRateSeeder extends Seeder
{
    /**
     * 2026 self-employed social-insurance rates and Pillar 3a limits.
     * Source: Settlo Tax Engine Algorithms v2.0, constants section.
     */
    public function run(): void
    {
        SocialInsuranceRate::updateOrCreate(
            ['year' => 2026],
            [
                'ahv_rate' => 10.6,
                'iv_rate' => 1.4,
                'eo_rate' => 0.5,
                'pillar3a_max_se' => 35280,
                'pillar3a_max_with_p2' => 7056,
                'ahv_minimum' => 514,
                'age_exemption_amount' => 16800,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
        );
    }
}
