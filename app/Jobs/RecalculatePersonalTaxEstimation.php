<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Tax\TaxEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalculates an owner's consolidated personal tax estimate and the share of
 * it carried by each of their sole-proprietorship workspaces. Dispatched after
 * a personal profile or tax profile change.
 */
class RecalculatePersonalTaxEstimation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Well inside the 60s serverless execution budget. */
    public int $timeout = 45;

    public bool $failOnTimeout = true;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    public function __construct(public int $userId, public ?int $fiscalYear = null) {}

    public function uniqueId(): string
    {
        return $this->userId.':'.($this->fiscalYear ?? 'current');
    }

    public function handle(TaxEngine $engine): void
    {
        $user = User::find($this->userId);

        if ($user !== null) {
            $engine->estimateAllFor($user, $this->fiscalYear);
        }
    }

    /**
     * A silently dropped recalculation leaves a stale personal estimate — and
     * stale per-workspace shares — on screen, so it is recorded with the ids
     * needed to replay it.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Personal tax estimation recalculation failed.', [
            'user_id' => $this->userId,
            'fiscal_year' => $this->fiscalYear,
            'exception' => $exception->getMessage(),
        ]);
    }
}
