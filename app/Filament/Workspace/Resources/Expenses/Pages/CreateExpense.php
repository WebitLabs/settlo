<?php

namespace App\Filament\Workspace\Resources\Expenses\Pages;

use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Filament\Workspace\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\Expenses\ExpenseService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    /** Set by "Create & confirm" so the new manual expense is confirmed right away. */
    public bool $shouldConfirmAfterCreate = false;

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getCreateFormAction(),
            Action::make('createAndConfirm')
                ->label('Create & confirm')
                ->color('success')
                ->visible(fn (): bool => blank($this->data['receipt_path'] ?? null))
                ->action('createAndConfirm'),
            ...($this->canCreateAnother() ? [$this->getCreateAnotherFormAction()] : []),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * Create a manual expense and confirm it in one step.
     */
    public function createAndConfirm(): void
    {
        $this->shouldConfirmAfterCreate = true;

        $this->create();
    }

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
        $data = ExpenseService::withDerivedVat($data);

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
            'amount' => $data['amount'] ?? 0,
            'net_amount' => round((float) ($data['amount'] ?? 0) - (float) ($data['vat_amount'] ?? 0), 2),
        ]);
        $expense->save();

        return $expense;
    }

    protected function afterCreate(): void
    {
        if (filled($this->record->receipt_path)) {
            app(ExpenseService::class)->startProcessing($this->record);

            return;
        }

        if ($this->shouldConfirmAfterCreate && ExpenseService::canBeConfirmed($this->record)) {
            app(ExpenseService::class)->confirm($this->record);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
