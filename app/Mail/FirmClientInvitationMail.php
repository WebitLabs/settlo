<?php

namespace App\Mail;

use App\Models\FirmClientInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent to a prospective client when a firm invites them. Carries the plain,
 * single-use token only in the accept URL — the token is never persisted in
 * plaintext, so this email is the one place it exists.
 */
class FirmClientInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly FirmClientInvitation $invitation,
        public readonly string $token,
        public readonly ?string $personalMessage = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to Settlo',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.firm.client-invitation',
            with: [
                'firmName' => $this->invitation->accountingFirm?->name ?? 'Your accountant',
                'acceptUrl' => route('firm-invitations.accept', ['token' => $this->token]),
                'personalMessage' => $this->personalMessage,
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
