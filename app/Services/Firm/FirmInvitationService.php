<?php

namespace App\Services\Firm;

use App\Mail\FirmClientInvitationMail;
use App\Models\AccountantAssignment;
use App\Models\AccountingFirm;
use App\Models\BusinessEntity;
use App\Models\FirmClientInvitation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Owns the firm→client invitation lifecycle. Invitation tokens are single-use
 * secrets: a 48-char random token is emailed to the client while only its
 * SHA-256 hash is ever persisted. Lookups compare hashes in constant time and
 * all guarded columns (token hash, acceptance, assignment ids) are written via
 * forceFill, never mass-assigned from a request.
 */
class FirmInvitationService
{
    private const EXPIRY_DAYS = 14;

    private const TOKEN_BYTES = 48;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Create and email a fresh invitation for a client email address.
     */
    public function invite(AccountingFirm $firm, string $email, User $invitedBy, ?string $message = null): FirmClientInvitation
    {
        $token = Str::random(self::TOKEN_BYTES);

        $invitation = new FirmClientInvitation;
        $invitation->forceFill([
            'accounting_firm_id' => $firm->getKey(),
            'invited_by_id' => $invitedBy->getKey(),
            'email' => Str::lower($email),
            'token_hash' => hash('sha256', $token),
            'expires_at' => Carbon::now()->addDays(self::EXPIRY_DAYS),
            'accepted_at' => null,
            'accepted_by_id' => null,
        ])->save();

        $this->auditLogger->log('firm.client_invited', $invitation, [
            'accounting_firm_id' => $firm->getKey(),
            'email' => $invitation->email,
        ], $invitedBy);

        $this->dispatchMail($invitation, $token, $message);

        return $invitation;
    }

    /**
     * Rotate the token on an existing pending invitation and re-send it. The old
     * token is invalidated immediately because only the newest hash is stored.
     */
    public function resend(FirmClientInvitation $invitation, ?string $message = null): FirmClientInvitation
    {
        $token = Str::random(self::TOKEN_BYTES);

        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => Carbon::now()->addDays(self::EXPIRY_DAYS),
        ])->save();

        $this->auditLogger->log('firm.client_invitation_resent', $invitation, [
            'accounting_firm_id' => $invitation->accounting_firm_id,
            'email' => $invitation->email,
        ]);

        $this->dispatchMail($invitation, $token, $message);

        return $invitation;
    }

    /**
     * Revoke a pending invitation: the stored hash is the only copy of the
     * token, so deleting the row invalidates the emailed link immediately.
     */
    public function revoke(FirmClientInvitation $invitation): void
    {
        $this->auditLogger->log('firm.client_invitation_revoked', $invitation, [
            'accounting_firm_id' => $invitation->accounting_firm_id,
            'email' => $invitation->email,
        ]);

        $invitation->delete();
    }

    /**
     * Resolve a plain token to its pending invitation, comparing hashes in
     * constant time. Returns null when no match, when already accepted, or when
     * expired.
     */
    public function findPendingByToken(string $token): ?FirmClientInvitation
    {
        $hash = hash('sha256', $token);

        $invitation = FirmClientInvitation::query()->where('token_hash', $hash)->first();

        if ($invitation === null) {
            return null;
        }

        if (! hash_equals($invitation->token_hash, $hash)) {
            return null;
        }

        return $invitation->isPending() ? $invitation : null;
    }

    /**
     * Accept an invitation for one of the owner's business entities: grant the
     * firm an active assignment, mark the invitation accepted, and notify the
     * firm's members. Assignment is firm-scoped (no specific accountant yet).
     */
    public function accept(FirmClientInvitation $invitation, BusinessEntity $entity, User $owner): AccountantAssignment
    {
        // (firm, business) is unique, so a previously revoked assignment still
        // occupies the slot: re-inviting a client the firm had let go used to
        // hit a duplicate-key error. Reinstating the existing row inside a
        // transaction (row-locked so two accepts cannot both insert) both fixes
        // that and keeps the accept path idempotent.
        $assignment = DB::transaction(function () use ($invitation, $entity): AccountantAssignment {
            $assignment = AccountantAssignment::query()
                ->where('accounting_firm_id', $invitation->accounting_firm_id)
                ->where('business_entity_id', $entity->getKey())
                ->lockForUpdate()
                ->first();

            if ($assignment === null) {
                $assignment = new AccountantAssignment;
                $assignment->forceFill([
                    'accounting_firm_id' => $invitation->accounting_firm_id,
                    'business_entity_id' => $entity->getKey(),
                    'accountant_id' => null,
                    'assigned_at' => Carbon::now(),
                    'revoked_at' => null,
                ])->save();

                return $assignment;
            }

            if (! $assignment->isActive()) {
                $assignment->forceFill([
                    'assigned_at' => Carbon::now(),
                    'revoked_at' => null,
                ])->save();
            }

            return $assignment;
        });

        $invitation->forceFill([
            'accepted_at' => Carbon::now(),
            'accepted_by_id' => $owner->getKey(),
        ])->save();

        $this->auditLogger->log('firm.client_invitation_accepted', $invitation, [
            'accounting_firm_id' => $invitation->accounting_firm_id,
            'business_entity_id' => $entity->getKey(),
            'assignment_id' => $assignment->getKey(),
        ], $owner);

        $this->notifyFirm($invitation, $entity);

        return $assignment;
    }

    private function dispatchMail(FirmClientInvitation $invitation, string $token, ?string $message): void
    {
        $invitation->loadMissing('accountingFirm');

        Mail::to($invitation->email)->send(
            new FirmClientInvitationMail($invitation, $token, $message),
        );
    }

    private function notifyFirm(FirmClientInvitation $invitation, BusinessEntity $entity): void
    {
        $members = $invitation->accountingFirm()->first()?->accountants()->get();

        if ($members === null || $members->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('New client accepted an invitation')
            ->body("{$entity->name} is now assigned to your firm.")
            ->icon('heroicon-o-building-office-2')
            ->success()
            ->sendToDatabase($members);
    }
}
