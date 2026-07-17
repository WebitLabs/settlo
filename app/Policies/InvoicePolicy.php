<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\User;

/**
 * Default-deny. An owner manages invoices only within a business they own.
 * Issued invoices are immutable: only drafts may be edited or deleted. Writes
 * require an access-granting subscription.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->owns($user, $invoice);
    }

    public function create(User $user): bool
    {
        return $user->isOwner() && $user->canWrite();
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->owns($user, $invoice)
            && $user->canWrite()
            && $invoice->status->isEditable();
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->owns($user, $invoice)
            && $invoice->status === InvoiceStatus::Draft;
    }

    public function restore(User $user, Invoice $invoice): bool
    {
        return $this->owns($user, $invoice) && $user->canWrite();
    }

    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return $this->owns($user, $invoice) && $invoice->status === InvoiceStatus::Draft;
    }

    private function owns(User $user, Invoice $invoice): bool
    {
        return $user->isOwner()
            && BusinessEntity::whereKey($invoice->business_entity_id)
                ->where('owner_id', $user->getKey())
                ->exists();
    }
}
