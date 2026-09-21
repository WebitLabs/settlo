<?php

namespace App\Console\Commands;

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Seeds the demo fixtures (Anna Müller, her two businesses, the accounting
 * firm and the Ask Settlo history) on a deployed instance. Outside local and
 * testing it refuses unless SETTLO_SEED_DEMO_DATA is on, and every account is
 * then created with a strong generated password that the seeder prints once.
 *
 * Reachable without a shell through the CRON_SECRET-guarded deploy endpoint,
 * which runs it when the same flag is set.
 */
class SeedDemoData extends Command
{
    protected $signature = 'settlo:seed-demo
        {--force : Seed even when SETTLO_SEED_DEMO_DATA is off}';

    protected $description = 'Seed the demo accounts and fixtures (generated passwords outside local/testing).';

    public function handle(): int
    {
        if (! $this->option('force') && ! DatabaseSeeder::shouldSeedDemoData()) {
            $this->error('Demo seeding is off. Set SETTLO_SEED_DEMO_DATA=true, or pass --force.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $this->info('Demo data seeded.');

        return self::SUCCESS;
    }
}
