<?php

namespace App\Filament\Workspace\Resources\Expenses\Pages;

use App\Enums\ExpenseStatus;
use App\Filament\Workspace\Resources\Expenses\ExpenseResource;
use App\Models\Expense;
use App\Services\Expenses\ExpenseService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
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
     * @return array<Action|ActionGroup>
     */
    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction(),
            Action::make('saveAndConfirm')
                ->label('Save & confirm')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->status === ExpenseStatus::PendingReview)
                ->action('saveAndConfirm'),
            $this->getCancelFormAction(),
        ];
    }

    /**
     * Save the reviewed details and confirm the expense in one step.
     */
    public function saveAndConfirm(): void
    {
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);

        /** @var Expense $expense */
        $expense = $this->getRecord();

        if (! ExpenseService::canBeConfirmed($expense)) {
            Notification::make()
                ->title('Add an amount and a category before confirming.')
                ->danger()
                ->send();

            return;
        }

        app(ExpenseService::class)->confirm($expense);
        Notification::make()->title('Expense saved and confirmed')->success()->send();

        $this->redirect($this->getResource()::getUrl('index'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $receiptPath = $data['receipt_path'] ?? $record->receipt_path;
        unset($data['receipt_path']);
        $data = ExpenseService::withDerivedVat($data);

        if (array_key_exists('amount', $data) && blank($data['amount'])) {
            $data['amount'] = 0;
        }

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
