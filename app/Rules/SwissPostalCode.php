<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A four-digit Swiss postal code (1000–9999), which also covers Liechtenstein
 * (9485–9498).
 */
class SwissPostalCode implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail('Enter a 4-digit Swiss postal code.');
        }
    }

    public static function isValid(string $postalCode): bool
    {
        return preg_match('/^[1-9]\d{3}$/', trim($postalCode)) === 1;
    }
}
