<?php

namespace App\Filament\Admin\Resources\Concerns;

use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;

/**
 * Applies a direct tax-configuration edit with forceFill and records the changed
 * value columns as a from/to diff under the taxconfig.updated audit action. Used
 * by the edit pages of resources that permit in-place corrections: future or
 * same-day effective-dated rows, and commune multipliers (which have no
 * versioning dimension).
 */
trait AuditsTaxConfigEdit
{
    abstract protected function taxConfigTable(): string;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var array<string, mixed> $original */
        $original = $record->only(array_keys($data));

        $record->forceFill($data);

        $changes = [];
        foreach (array_keys($record->getDirty()) as $attribute) {
            $changes[$attribute] = [
                'from' => $original[$attribute] ?? null,
                'to' => $record->getAttribute($attribute),
            ];
        }

        $record->save();

        if ($changes !== []) {
            app(AuditLogger::class)->log('taxconfig.updated', $record, [
                'table' => $this->taxConfigTable(),
                'operation' => 'edit',
                'changes' => $changes,
            ]);
        }

        return $record;
    }
}
