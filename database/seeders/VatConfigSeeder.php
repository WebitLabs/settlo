<?php

namespace Database\Seeders;

use App\Models\VatConfig;
use Illuminate\Database\Seeder;

class VatConfigSeeder extends Seeder
{
    /**
     * 2026 Swiss VAT rates and registration threshold. Source: Settlo Tax
     * Engine Algorithms v2.0, VAT constants.
     */
    public function run(): void
    {
        VatConfig::updateOrCreate(
            ['year' => 2026],
            [
                'standard_rate' => 8.1,
                'reduced_rate' => 2.6,
                'special_rate' => 3.8,
                'registration_threshold' => 100000,
                'registration_window_days' => 30,
                'effective_from' => '2026-01-01',
                'effective_to' => null,
            ],
        );
    }
}
