<?php

namespace App\Filament\App\Resources\Expenses\Pages;

use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Filament\App\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\Expenses\ExpenseService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    /**
     * The tenant, review status, receipt path and processing state are guarded
     * and set server-side. A receipt starts in the "pending" pipeline state; a
     * manual entry is marked "manual".
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $entity = Filament::getTenant();
        $receiptPath = $data['receipt_path'] ?? null;
        unset($data['receipt_path']);

        $expense = new Expense;
        $expense->fill($data);
        $expense->forceFill([
            'business_entity_id' => $entity->getKey(),
            'status' => ExpenseStatus::PendingReview->value,
            'receipt_path' => $receiptPath,
            'processing_status' => $receiptPath
                ? ExpenseProcessingStatus::Pending->value
                : ExpenseProcessingStatus::Manual->value,
            'currency_code' => $data['currency_code'] ?? ($entity->default_currency ?: 'CHF'),
            'net_amount' => round((float) ($data['amount'] ?? 0) - (float) ($data['vat_amount'] ?? 0), 2),
        ]);
        $expense->save();

        return $expense;
    }

    protected function afterCreate(): void
    {
        if (filled($this->record->receipt_path)) {
            app(ExpenseService::class)->startProcessing($this->record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
