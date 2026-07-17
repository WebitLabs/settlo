<?php

namespace Database\Seeders;

use App\Models\FederalTaxBracket;
use Illuminate\Database\Seeder;

class FederalTaxBracketSeeder extends Seeder
{
    /**
     * 2026 federal direct-tax brackets. Source: Settlo Tax Engine Algorithms
     * v2.0, Section 3.4/3.5. Tariff A = single; Tariff B = married / single
     * parent. Rows are [from, to (null = top), rate %, base CHF]. Tax within a
     * bracket = base + (income - from) × rate / 100.
     */
    public function run(): void
    {
        $tariffA = [
            [0, 14500, 0.00, 0.00],
            [14500, 31600, 0.77, 0.00],
            [31600, 41400, 0.88, 131.65],
            [41400, 55200, 2.64, 217.90],
            [55200, 72500, 2.97, 582.10],
            [72500, 78100, 5.94, 1095.55],
            [78100, 103600, 6.60, 1427.90],
            [103600, 134600, 8.80, 3110.50],
            [134600, 176000, 11.00, 5838.50],
            [176000, 755200, 13.20, 10392.50],
            [755200, null, 11.50, 87000.00],
        ];

        $tariffB = [
            [0, 28300, 0.00, 0.00],
            [28300, 50900, 1.00, 0.00],
            [50900, 58400, 2.00, 226.00],
            [58400, 75300, 3.00, 376.00],
            [75300, 90300, 4.00, 883.00],
            [90300, 103400, 5.00, 1483.00],
            [103400, 114700, 6.00, 2138.00],
            [114700, 124000, 7.00, 2816.00],
            [124000, 131200, 8.00, 3467.00],
            [131200, 141200, 9.00, 4043.00],
            [141200, 755200, 11.50, 4943.00],
            [755200, null, 11.50, 75550.00],
        ];

        foreach (['A' => $tariffA, 'B' => $tariffB] as $tariff => $brackets) {
            foreach ($brackets as [$from, $to, $rate, $base]) {
                FederalTaxBracket::updateOrCreate(
                    ['year' => 2026, 'tariff' => $tariff, 'bracket_from' => $from],
                    [
                        'bracket_to' => $to,
                        'rate' => $rate,
                        'base_amount' => $base,
                        'effective_from' => '2026-01-01',
                        'effective_to' => null,
                    ],
                );
            }
        }
    }
}
