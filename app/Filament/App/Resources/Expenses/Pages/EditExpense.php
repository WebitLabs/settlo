<?php

namespace App\Filament\App\Resources\Expenses\Pages;

use App\Filament\App\Resources\Expenses\ExpenseResource;
use App\Services\Expenses\ExpenseService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $receiptPath = $data['receipt_path'] ?? $record->receipt_path;
        unset($data['receipt_path']);

        $record->fill($data);
        $record->forceFill([
            'receipt_path' => $receiptPath,
            'net_amount' => round(
                (float) ($data['amount'] ?? $record->amount) - (float) ($data['vat_amount'] ?? $record->vat_amount),
                2,
            ),
        ]);
        $record->save();

        return $record;
    }

    protected function afterSave(): void
    {
        app(ExpenseService::class)->recomputeDeductible($this->record);
    }
}
