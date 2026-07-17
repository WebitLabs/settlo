<?php

namespace App\Filament\Admin\Resources\Plans\Pages;

use App\Filament\Admin\Resources\Plans\PlanResource;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    /**
     * Apply the edit with forceFill and audit the changed attributes as a
     * from/to diff. The `code` is dehydrated out on edit, so it can never reach
     * this payload.
     *
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
            app(AuditLogger::class)->log('plan.updated', $record, ['changes' => $changes]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
