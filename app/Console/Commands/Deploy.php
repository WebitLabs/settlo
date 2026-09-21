<?php

namespace App\Console\Commands;

use App\Models\Canton;
use App\Models\Commune;
use App\Models\ExpenseCategory;
use App\Models\Plan;
use App\Models\PostalCode;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * The deploy step for environments with no shell (serverless): run pending
 * migrations, (re)seed the idempotent reference data every environment needs,
 * then verify the tables are actually populated. Reachable over HTTP through
 * the CRON_SECRET-guarded /cron/deploy endpoint.
 */
class Deploy extends Command
{
    protected $signature = 'settlo:deploy
        {--skip-migrations : Only seed the reference data}';

    protected $description = 'Run pending migrations and seed the reference data required in every environment.';

    /**
     * Reference tables that must not be empty after a deploy.
     *
     * @var array<string, class-string<Model>>
     */
    private const array REQUIRED_TABLES = [
        'cantons' => Canton::class,
        'communes' => Commune::class,
        'postal codes' => PostalCode::class,
        'expense categories' => ExpenseCategory::class,
        'plans' => Plan::class,
    ];

    public function handle(): int
    {
        if (! $this->option('skip-migrations') && $this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Migrations failed.');

            return self::FAILURE;
        }

        if ($this->call('db:seed', ['--class' => ReferenceDataSeeder::class, '--force' => true]) !== self::SUCCESS) {
            $this->error('Reference data seeding failed.');

            return self::FAILURE;
        }

        // Opt-in demo fixtures for a deployed demo instance. The seeder prints
        // the generated credentials once — capture them from this output.
        if (DatabaseSeeder::shouldSeedDemoData() && $this->call('settlo:seed-demo') !== self::SUCCESS) {
            $this->error('Demo seeding failed.');

            return self::FAILURE;
        }

        return $this->verify();
    }

    /**
     * Seeders are idempotent and swallow nothing, but an empty reference table
     * after a "successful" run is the exact failure mode that shipped empty
     * canton and commune pickers to production, so it is checked explicitly.
     */
    private function verify(): int
    {
        $empty = [];

        foreach (self::REQUIRED_TABLES as $label => $model) {
            $count = $model::query()->count();

            $count === 0 ? $empty[] = $label : $this->line(sprintf('  %-20s %d', $label, $count));
        }

        if ($empty !== []) {
            $this->error('Reference data is still missing after seeding: '.implode(', ', $empty).'.');

            return self::FAILURE;
        }

        $this->info('Deploy complete: migrations applied and reference data present.');

        return self::SUCCESS;
    }
}
