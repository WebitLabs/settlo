<?php

namespace Database\Seeders;

use App\Services\Geo\PostalCodeImporter;
use Illuminate\Database\Seeder;

/**
 * Every Swiss postal code locality from the committed swisstopo directory
 * (database/data/postal_codes.csv, refreshed with
 * `php artisan settlo:import-postal-codes --export`), so seeding is offline
 * and deterministic.
 */
class PostalCodeSeeder extends Seeder
{
    public function run(PostalCodeImporter $importer): void
    {
        $importer->import($importer->readExport(database_path('data/postal_codes.csv')));
    }
}
