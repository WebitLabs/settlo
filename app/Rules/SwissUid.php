<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A Swiss enterprise identification number (UID, eCH-0097) with a valid check
 * digit. Accepts "CHE-123.456.789", "CHE123456789" and "CHE 123 456 789".
 */
class SwissUid implements ValidationRule
{
    /** eCH-0097 weights for digits 1–8. */
    private const array WEIGHTS = [5, 4, 3, 2, 7, 6, 5, 4];

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail('Enter a valid Swiss UID, e.g. CHE-123.456.788.');
        }
    }

    public static function isValid(string $uid): bool
    {
        return self::normalize($uid) !== null;
    }

    /**
     * The canonical "CHE-123.456.789" form, or null when the value is not a
     * valid UID (wrong format or check digit).
     */
    public static function normalize(string $uid): ?string
    {
        $digits = self::digits($uid);

        if ($digits === null || ! self::hasValidCheckDigit($digits)) {
            return null;
        }

        return sprintf('CHE-%s.%s.%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3));
    }

    /**
     * The nine UID digits, or null when the value does not look like a UID.
     */
    public static function digits(string $uid): ?string
    {
        $compact = strtoupper((string) preg_replace('/[\s.\-]+/', '', $uid));

        if (preg_match('/^CHE(\d{9})$/', $compact, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private static function hasValidCheckDigit(string $digits): bool
    {
        $sum = 0;
        foreach (self::WEIGHTS as $index => $weight) {
            $sum += (int) $digits[$index] * $weight;
        }

        $check = 11 - ($sum % 11);

        if ($check === 10) {
            return false;
        }

        if ($check === 11) {
            $check = 0;
        }

        return $check === (int) $digits[8];
    }
}
