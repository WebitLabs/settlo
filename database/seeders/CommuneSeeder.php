<?php

namespace Database\Seeders;

use App\Models\Canton;
use App\Models\Commune;
use Illuminate\Database\Seeder;

class CommuneSeeder extends Seeder
{
    /**
     * A handful of communes needed for the demo and tests. Zürich city (119)
     * and Küsnacht (70) exercise the multiplier difference within one canton;
     * canton capitals are covered by the canton default multiplier fallback.
     */
    public function run(): void
    {
        $communes = [
            ['ZH', 'Zürich', '261', 119],
            ['ZH', 'Küsnacht', '154', 70],
            ['ZH', 'Winterthur', '230', 125],
            ['ZG', 'Zug', '1711', 138],
            ['GE', 'Genève', '6621', 100],
            ['BS', 'Basel', '2701', 100],
        ];

        foreach ($communes as [$cantonCode, $name, $bfs, $multiplier]) {
            $canton = Canton::where('code', $cantonCode)->first();
            if (! $canton) {
                continue;
            }

            Commune::updateOrCreate(
                ['canton_id' => $canton->id, 'bfs_number' => $bfs],
                [
                    'name' => $name,
                    'tax_multiplier' => $multiplier,
                    'effective_from' => '2026-01-01',
                    'effective_to' => null,
                ],
            );
        }
    }
}
