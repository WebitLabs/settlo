<?php

namespace App\Billing;

use RuntimeException;

class QuotaExceededException extends RuntimeException
{
    public static function humanAnswers(): self
    {
        return new self('Monthly human-answer quota exceeded for this plan.');
    }
}
