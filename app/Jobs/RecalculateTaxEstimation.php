<?php

namespace App\Jobs;

use App\Models\BusinessEntity;
use App\Services\Tax\TaxEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recalculates the tax estimation for a business entity off the request cycle.
 * For a sole proprietorship the engine refreshes the owner's personal estimate
 * and every sibling workspace's share, since one business' change moves all
 * shares. Carries the entity id explicitly (never reads Filament::getTenant()) so it
 * runs with the correct tenant context on any queue worker.
 */
class RecalculateTaxEstimation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Well inside the 60s serverless execution budget. */
    public int $timeout = 45;

    public bool $failOnTimeout = true;

    /** @var array<int, int> */
    public array $backoff = [5, 30];

    public function __construct(public string $businessEntityId, public ?int $fiscalYear = null) {}

    public function uniqueId(): string
    {
        return $this->businessEntityId.':'.($this->fiscalYear ?? 'current');
    }

    public function handle(TaxEngine $engine): void
    {
        $entity = BusinessEntity::find($this->businessEntityId);

        if ($entity !== null) {
            $engine->estimateFor($entity, $this->fiscalYear);
        }
    }

    /**
     * A silently dropped recalculation leaves a stale tax estimate on screen,
     * so the exhausted job is recorded with the ids needed to replay it.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Tax estimation recalculation failed.', [
            'business_entity_id' => $this->businessEntityId,
            'fiscal_year' => $this->fiscalYear,
            'exception' => $exception->getMessage(),
        ]);
    }
}
