<?php

namespace App\Services\Registry;

use RuntimeException;
use Throwable;

/**
 * The Swiss UID register could not be queried: it is unreachable, answered
 * with an error, or the per-user lookup limit was reached.
 */
class UidRegisterUnavailable extends RuntimeException
{
    public function __construct(string $message = 'The UID register is not reachable.', public readonly ?int $retryAfterSeconds = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self('Too many UID lookups.', $retryAfterSeconds);
    }

    public function isRateLimited(): bool
    {
        return $this->retryAfterSeconds !== null;
    }
}
