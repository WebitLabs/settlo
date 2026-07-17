<?php

namespace App\Jobs;

use App\Models\BusinessEntity;
use App\Services\Tax\TaxEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Recalculates the tax estimation for a business entity off the request cycle.
 * Carries the entity id explicitly (never reads Filament::getTenant()) so it
 * runs with the correct tenant context on any queue worker.
 */
class RecalculateTaxEstimation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

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
}
