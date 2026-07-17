<?php

namespace App\Filament\Admin\Resources\AccountingFirms\Pages;

use App\Filament\Admin\Resources\AccountingFirms\AccountingFirmResource;
use App\Models\AccountingFirm;
use App\Models\AccountingFirmMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateAccountingFirm extends CreateRecord
{
    protected static string $resource = AccountingFirmResource::class;

    /**
     * Create the firm and seed it with its first owner-member. The owner email
     * is validated in the form to belong to an existing accountant, so the
     * lookup here is safe. The firm columns are written with forceFill and the
     * whole provisioning is recorded as firm.created.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $ownerEmail = $data['owner_email'];

        /** @var User $owner */
        $owner = User::query()->where('email', $ownerEmail)->firstOrFail();

        $firm = new AccountingFirm;
        $firm->forceFill(Arr::except($data, ['owner_email']))->save();

        AccountingFirmMember::create([
            'accounting_firm_id' => $firm->getKey(),
            'user_id' => $owner->getKey(),
            'is_owner' => true,
            'joined_at' => now(),
        ]);

        app(AuditLogger::class)->log('firm.created', $firm, [
            'owner_id' => $owner->getKey(),
            'owner_email' => $owner->email,
        ]);

        return $firm;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }
}
