<?php

namespace App\Filament\App\Resources\BankAccounts\Pages;

use App\Filament\App\Resources\BankAccounts\BankAccountResource;
use App\Rules\ValidIban;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditBankAccount extends EditRecord
{
    protected static string $resource = BankAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * The IBAN and the default flag are written via forceFill; toggling this
     * account to default clears the flag on the tenant's other accounts so a
     * single default is always maintained.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $isDefault = (bool) ($data['is_default'] ?? false);

        $record->fill($data);
        $record->forceFill([
            'iban' => ValidIban::normalize((string) ($data['iban'] ?? '')),
            'is_default' => $isDefault,
        ]);
        $record->save();

        if ($isDefault) {
            Filament::getTenant()->bankAccounts()
                ->whereKeyNot($record->getKey())
                ->update(['is_default' => false]);
        }

        return $record;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
