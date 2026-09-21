<?php

namespace App\Services\Phone;

use App\Models\User;

/**
 * Sends and checks the one-time code that confirms a user's mobile number.
 * A real SMS driver (e.g. Twilio Verify, ASPSMS) implements this later.
 */
interface PhoneVerifier
{
    /**
     * Send a new code to the user's phone, replacing any pending one.
     */
    public function sendCode(User $user): void;

    /**
     * Whether the code matches the pending one. Fails once the code expired or
     * too many wrong attempts were made.
     */
    public function verify(User $user, string $code): bool;

    /**
     * Whether a code was sent and is still valid.
     */
    public function hasPendingCode(User $user): bool;

    /**
     * Discard the pending code, e.g. because the number it was sent to changed.
     */
    public function forget(User $user): void;
}
