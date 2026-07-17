<?php

namespace App\Filament\App\Resources\BankAccounts\Pages;

use App\Filament\App\Resources\BankAccounts\BankAccountResource;
use App\Models\BankAccount;
use App\Rules\ValidIban;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateBankAccount extends CreateRecord
{
    protected static string $resource = BankAccountResource::class;

    /**
     * business_entity_id, the normalised IBAN and the default flag are assigned
     * server-side via forceFill. When this account is marked default, any other
     * default for the same tenant is cleared so exactly one remains.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        $isDefault = (bool) ($data['is_default'] ?? false);

        // The very first account is always the default.
        if ($tenant->bankAccounts()->count() === 0) {
            $isDefault = true;
        }

        $account = new BankAccount;
        $account->fill($data);
        $account->forceFill([
            'business_entity_id' => $tenant->getKey(),
            'iban' => ValidIban::normalize((string) ($data['iban'] ?? '')),
            'is_default' => $isDefault,
        ]);
        $account->save();

        if ($isDefault) {
            $tenant->bankAccounts()
                ->whereKeyNot($account->getKey())
                ->update(['is_default' => false]);
        }

        return $account;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
