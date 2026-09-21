<?php

namespace App\Support;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A throttle for expensive work that is triggered from inside a Livewire panel
 * action rather than through a dedicated HTTP route, where route middleware
 * cannot reach: PDF rendering and receipt OCR both cost real CPU or third-party
 * spend and are otherwise unmetered. Counting is per actor (user or workspace)
 * so one tenant can never exhaust another's budget.
 */
final class ActionThrottle
{
    /**
     * Record an attempt, refusing it once the per-actor allowance is spent.
     *
     * @throws ThrottleRequestsException
     */
    public static function hit(string $action, string $scope, int $maxAttempts, int $decaySeconds = 60): void
    {
        $key = self::key($action, $scope);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw new ThrottleRequestsException(
                'Too many requests. Please try again in '.RateLimiter::availableIn($key).' seconds.',
            );
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    public static function clear(string $action, string $scope): void
    {
        RateLimiter::clear(self::key($action, $scope));
    }

    private static function key(string $action, string $scope): string
    {
        return 'settlo-action:'.$action.':'.sha1($scope);
    }
}
