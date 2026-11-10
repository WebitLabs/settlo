<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Temporary: wipes every table and re-runs the migrations, so a deployed demo
 * instance can start over. Reference data and demo accounts are put back
 * separately with settlo:deploy and settlo:seed-demo, keeping each HTTP call
 * within the serverless time limit. Remove once the reset is done.
 */
class FreshDatabase extends Command
{
    protected $signature = 'settlo:fresh';

    protected $description = 'Drop every table and re-run the migrations (all data is lost).';

    public function handle(): int
    {
        if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Fresh migration failed.');

            return self::FAILURE;
        }

        $this->info('Database wiped and migrated. Run settlo:deploy, then settlo:seed-demo.');

        return self::SUCCESS;
    }
}
