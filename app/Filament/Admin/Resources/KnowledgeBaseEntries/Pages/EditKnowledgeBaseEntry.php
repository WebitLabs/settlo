<?php

namespace App\Filament\Admin\Resources\KnowledgeBaseEntries\Pages;

use App\Filament\Admin\Resources\KnowledgeBaseEntries\KnowledgeBaseEntryResource;
use App\Services\Audit\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditKnowledgeBaseEntry extends EditRecord
{
    protected static string $resource = KnowledgeBaseEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function (Model $record): void {
                    app(AuditLogger::class)->log('kb.deleted', $record, [
                        'question' => $record->question,
                    ]);
                }),
        ];
    }

    /**
     * Apply the text correction and audit the changed attributes as a from/to
     * diff under the kb.updated action; no audit row is written when nothing
     * actually changed.
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
            app(AuditLogger::class)->log('kb.updated', $record, ['changes' => $changes]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
