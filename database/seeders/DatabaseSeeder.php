<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data is required in every environment.
        $this->call(ReferenceDataSeeder::class);

        // Demo fixtures ship weak credentials — never seed them outside
        // local/testing.
        if (app()->environment(['local', 'testing'])) {
            $this->call(DemoSeeder::class);
        }
    }
}
