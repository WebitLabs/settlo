<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference data is required in every environment.
        $this->call(ReferenceDataSeeder::class);

        if (self::shouldSeedDemoData()) {
            $this->call(DemoSeeder::class);
        }
    }

    /**
     * Demo fixtures are always seeded in local and testing, where they carry
     * the fixed weak password. Anywhere else they create real logins, so they
     * only run when SETTLO_SEED_DEMO_DATA is explicitly on — and then with
     * strong generated passwords (see {@see DemoSeeder}).
     */
    public static function shouldSeedDemoData(): bool
    {
        return app()->environment(['local', 'testing'])
            || (bool) config('settlo.demo.seed_outside_local', false);
    }
}
