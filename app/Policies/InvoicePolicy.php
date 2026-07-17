<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\BusinessEntity;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Default-deny. An owner manages invoices only within a business they own.
 * An accountant with an active assignment through a firm they are a member of
 * may view (read-only) — every write path stays owner-only. Issued invoices are
 * immutable: only drafts may be edited or deleted. Writes require an
 * access-granting subscription.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner() || $user->isAccountant();
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->owns($user, $invoice)
            || $this->assignedAccountant($user, $invoice->business_entity_id);
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

    /**
     * An accountant may read a client's books only through an active
     * (non-revoked) assignment owned by a firm they are a member of.
     */
    private function assignedAccountant(User $user, string $businessEntityId): bool
    {
        return $user->isAccountant()
            && $user->accountingFirms()
                ->whereHas('activeAssignments', fn (Builder $query) => $query
                    ->where('business_entity_id', $businessEntityId))
                ->exists();
    }
}
