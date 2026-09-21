<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\Worker;

/**
 * Works the queue until it is empty and then exits, so a serverless deploy
 * with no long-lived worker can still run queued jobs: an external cron hits
 * /cron/drain-queue every minute and this drains whatever is waiting.
 *
 * `--max-time` keeps the process well inside the function's execution limit;
 * anything left over is picked up by the next tick.
 */
class DrainQueue extends Command
{
    protected $signature = 'settlo:drain-queue
        {--queue= : Comma-separated queues, in priority order}
        {--max-time= : Seconds to keep working before exiting}
        {--tries= : Attempts per job}';

    protected $description = 'Run queued jobs until the queue is empty (serverless stand-in for a worker).';

    public function handle(): int
    {
        $connection = (string) config('queue.default');

        // The sync driver already ran the job inside the dispatching request.
        if ($connection === 'sync') {
            $this->info('The queue connection is [sync]: jobs run inline, nothing to drain.');

            return self::SUCCESS;
        }

        $exitCode = $this->call('queue:work', [
            'connection' => $connection,
            '--queue' => (string) ($this->option('queue') ?: config('settlo.queue_drain.queues')),
            '--stop-when-empty' => true,
            '--max-time' => (int) ($this->option('max-time') ?: config('settlo.queue_drain.max_time')),
            '--tries' => (int) ($this->option('tries') ?: config('settlo.queue_drain.tries')),
            // The worker runs inside an already-booted request here, so the
            // framework's 128 MB default would stop it before it starts.
            '--memory' => (int) config('settlo.queue_drain.memory'),
        ]);

        // Hitting the memory ceiling is a normal stop for a drained worker:
        // whatever is left is picked up by the next tick, so the cron ping
        // must not be reported as a failure.
        if ($exitCode === Worker::EXIT_MEMORY_LIMIT) {
            $this->warn('The worker reached its memory ceiling; the remaining jobs are drained on the next run.');

            return self::SUCCESS;
        }

        return $exitCode;
    }
}
