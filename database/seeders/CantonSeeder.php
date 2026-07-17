<?php

namespace Database\Seeders;

use App\Models\Canton;
use App\Models\CantonFiscalConfig;
use Illuminate\Database\Seeder;

class CantonSeeder extends Seeder
{
    /**
     * 26 cantons with their 2026 fiscal configuration. Source of truth:
     * Settlo Tax Engine Algorithms v2.0, Section 7 (canton table). Values are
     * [code, name_de, name_en, capital, cantonal_rate %, communal_multiplier
     * (capital) %, church_rate %, child_deduction CHF].
     */
    public function run(): void
    {
        $cantons = [
            ['ZH', 'Zürich', 'Zurich', 'Zürich', 8.00, 119, 10, 9000],
            ['BE', 'Bern', 'Bern', 'Bern', 8.84, 155, 11, 8500],
            ['LU', 'Luzern', 'Lucerne', 'Luzern', 5.17, 160, 9, 7000],
            ['UR', 'Uri', 'Uri', 'Altdorf', 4.26, 260, 8, 6500],
            ['SZ', 'Schwyz', 'Schwyz', 'Schwyz', 2.75, 170, 8, 7500],
            ['OW', 'Obwalden', 'Obwalden', 'Sarnen', 4.96, 205, 9, 6500],
            ['NW', 'Nidwalden', 'Nidwalden', 'Stans', 3.97, 200, 8, 6500],
            ['GL', 'Glarus', 'Glarus', 'Glarus', 7.00, 100, 10, 7000],
            ['ZG', 'Zug', 'Zug', 'Zug', 2.43, 138, 8, 8000],
            ['FR', 'Freiburg', 'Fribourg', 'Fribourg', 7.21, 175, 11, 7500],
            ['SO', 'Solothurn', 'Solothurn', 'Solothurn', 6.50, 195, 10, 7000],
            ['BS', 'Basel-Stadt', 'Basel-City', 'Basel', 8.95, 100, 12, 7500],
            ['BL', 'Basel-Landschaft', 'Basel-Country', 'Liestal', 7.75, 160, 11, 7000],
            ['SH', 'Schaffhausen', 'Schaffhausen', 'Schaffhausen', 5.30, 195, 10, 7000],
            ['AR', 'Appenzell Ausserrhoden', 'Appenzell Outer Rhodes', 'Herisau', 5.83, 155, 8, 6500],
            ['AI', 'Appenzell Innerrhoden', 'Appenzell Inner Rhodes', 'Appenzell', 3.80, 120, 8, 6500],
            ['SG', 'St. Gallen', 'St. Gallen', 'St. Gallen', 6.75, 155, 9, 7000],
            ['GR', 'Graubünden', 'Grisons', 'Chur', 6.50, 185, 9, 6500],
            ['AG', 'Aargau', 'Aargau', 'Aarau', 8.03, 130, 10, 7500],
            ['TG', 'Thurgau', 'Thurgau', 'Frauenfeld', 6.00, 142, 9, 7000],
            ['TI', 'Tessin', 'Ticino', 'Bellinzona', 8.01, 100, 10, 7000],
            ['VD', 'Waadt', 'Vaud', 'Lausanne', 8.76, 134, 11, 7500],
            ['VS', 'Wallis', 'Valais', 'Sion', 6.85, 155, 10, 7000],
            ['NE', 'Neuenburg', 'Neuchâtel', 'Neuchâtel', 9.24, 100, 11, 7500],
            ['GE', 'Genf', 'Geneva', 'Genève', 7.44, 100, 11, 9000],
            ['JU', 'Jura', 'Jura', 'Delémont', 8.00, 168, 11, 7000],
        ];

        foreach ($cantons as [$code, $nameDe, $nameEn, $capital, $cantRate, $commMult, $kirchRate, $childDed]) {
            $canton = Canton::updateOrCreate(
                ['code' => $code],
                [
                    'name_de' => $nameDe,
                    'name_fr' => $nameEn,
                    'name_it' => $nameEn,
                    'name_en' => $nameEn,
                    'capital' => $capital,
                ],
            );

            CantonFiscalConfig::updateOrCreate(
                ['canton_id' => $canton->id, 'year' => 2026],
                [
                    'cantonal_rate' => $cantRate,
                    'communal_multiplier_default' => $commMult,
                    'church_rate' => $kirchRate,
                    'child_deduction' => $childDed,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ],
            );
        }
    }
}
