<?php

namespace App\Filament\Shared\Auth;

use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Password;

/**
 * Password reset that does not leak whether an address has an account.
 *
 * Filament's page reports the broker status verbatim, so an unknown address
 * answers "We can't find a user with that email address." while a known one
 * answers "We have emailed your password reset link." — a free account-
 * enumeration oracle on a public, unauthenticated form. Every outcome here
 * renders the same neutral confirmation instead; the real status is still
 * honoured internally, so a link is only ever mailed to a real account.
 *
 * The throttle is also tightened: Filament's default (2 per minute per IP) is
 * generous for a form whose only legitimate use is a handful of attempts, and
 * the responses are now indistinguishable, so probing is limited by rate alone.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    /**
     * Requests per IP per decay window.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * Length of the throttling window, in seconds.
     */
    private const DECAY_SECONDS = 300;

    /**
     * Clamp whatever limit the parent page asks for down to this panel's own,
     * stricter window. Overriding the limiter rather than copying request()
     * keeps the page in step with future Filament changes.
     *
     * @param  int  $maxAttempts
     * @param  int  $decaySeconds
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function rateLimit($maxAttempts, $decaySeconds = 60, $method = null, $component = null): void
    {
        parent::rateLimit(
            min((int) $maxAttempts, self::MAX_ATTEMPTS),
            max((int) $decaySeconds, self::DECAY_SECONDS),
            $method ?? 'request',
            $component ?? static::class,
        );
    }

    /**
     * A failure (unknown address, throttled broker, …) is reported exactly like
     * a success, so the response carries no information about the account.
     */
    protected function getFailureNotification(string $status): ?Notification
    {
        return $this->getSentNotification(Password::RESET_LINK_SENT);
    }

    /**
     * The rate-limit notice is the one honest failure: it says nothing about
     * any account, only that this client must wait.
     */
    protected function getRateLimitedNotification(TooManyRequestsException $exception): ?Notification
    {
        return Notification::make()
            ->title('Too many requests')
            ->body("Please wait {$exception->secondsUntilAvailable} seconds before trying again.")
            ->danger();
    }
}
