<?php

namespace Database\Seeders;

use App\Models\Canton;
use App\Models\Commune;
use App\Services\Communes\CommuneImporter;
use Illuminate\Database\Seeder;

/**
 * Every Swiss commune from the committed BFS snapshot (database/data/communes.csv,
 * refreshed with `php artisan settlo:import-communes --export`), so seeding is
 * offline and deterministic. Communes get the canton's default multiplier
 * (flagged as estimated) except the few whose real Steuerfuss is known.
 */
class CommuneSeeder extends Seeder
{
    /** Snapshot date of the committed file. */
    private const string SNAPSHOT_DATE = '2026-01-01';

    /**
     * Known communal multipliers. Zürich city (119) and Küsnacht (70) exercise
     * the multiplier difference within one canton.
     *
     * Rows are canton code, BFS number and multiplier: Zürich, Küsnacht,
     * Winterthur, Zug, Genève and Basel.
     *
     * @var list<array{0: string, 1: string, 2: int}>
     */
    private const array KNOWN_MULTIPLIERS = [
        ['ZH', '261', 119],
        ['ZH', '154', 70],
        ['ZH', '230', 125],
        ['ZG', '1711', 138],
        ['GE', '6621', 100],
        ['BS', '2701', 100],
    ];

    public function run(CommuneImporter $importer): void
    {
        $importer->import(
            $importer->readExport(database_path('data/communes.csv')),
            CommuneImporter::parseDate(self::SNAPSHOT_DATE),
        );

        foreach (self::KNOWN_MULTIPLIERS as [$cantonCode, $bfs, $multiplier]) {
            $cantonId = Canton::where('code', $cantonCode)->value('id');
            if ($cantonId === null) {
                continue;
            }

            Commune::query()
                ->where('canton_id', $cantonId)
                ->where('bfs_number', $bfs)
                ->update(['tax_multiplier' => $multiplier, 'multiplier_is_estimated' => false]);
        }
    }
}
