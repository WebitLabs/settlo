<?php

namespace App\Services\Tax;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * The single write path for superseding an in-force tax-configuration row with
 * a new effective-dated version. Tax rates are never silently mutated: creating
 * a new period end-dates the currently open row (its effective_to becomes the
 * day before the new period begins) and inserts a fresh row that carries the new
 * values forward with an open (null) effective_to. Every supersession is
 * recorded in the append-only audit trail.
 */
class EffectiveDatedConfigWriter
{
    public function __construct(private AuditLogger $auditLogger) {}

    /**
     * End-date the currently open row and create the next version from the
     * submitted form values. Returns the newly created row.
     *
     * @param  array<string, mixed>  $data  New-period column values, including effective_from.
     */
    public function newVersion(Model $current, array $data, string $table): Model
    {
        $effectiveFrom = Carbon::parse($data['effective_from'])->startOfDay();

        /** @var array<string, mixed> $before */
        $before = $current->only($current->getFillable());

        $current->forceFill([
            'effective_to' => $effectiveFrom->copy()->subDay()->toDateString(),
        ])->save();

        $new = $current->newInstance();
        $new->forceFill(array_merge($data, ['effective_to' => null]))->save();

        $this->auditLogger->log('taxconfig.updated', $new, [
            'table' => $table,
            'operation' => 'new_version',
            'supersedes' => (string) $current->getKey(),
            'effective_from' => $effectiveFrom->toDateString(),
            'previous_effective_to' => $current->effective_to?->toDateString(),
            'changes' => $this->changes($before, $data),
        ]);

        return $new;
    }

    /**
     * Build a from/to diff of the value columns (dates excluded) between the
     * superseded row and the submitted new-period values.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $data
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function changes(array $before, array $data): array
    {
        $changes = [];

        foreach (Arr::except($data, ['effective_from', 'effective_to']) as $key => $value) {
            $old = $before[$key] ?? null;

            if ((string) $old !== (string) $value) {
                $changes[$key] = ['from' => $old, 'to' => $value];
            }
        }

        return $changes;
    }
}
