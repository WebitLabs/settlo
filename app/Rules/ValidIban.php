<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a Swiss (CH) or Liechtenstein (LI) IBAN: correct country code,
 * 21-character length, and a passing ISO 7064 mod-97 checksum. Used both as a
 * Filament/validator rule and, via the static helper, in server-side code.
 */
class ValidIban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value)) {
            $fail('Enter a valid Swiss (CH) or Liechtenstein (LI) IBAN.');
        }
    }

    public static function isValid(string $iban): bool
    {
        $normalized = self::normalize($iban);

        if (preg_match('/^(CH|LI)\d{19}$/', $normalized) !== 1) {
            return false;
        }

        return self::mod97($normalized) === 1;
    }

    /**
     * Uppercase the IBAN and strip all whitespace so formatting variations
     * ("CH93 0076 …" vs "CH930076…") validate and store identically.
     */
    public static function normalize(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    /**
     * ISO 7064 mod-97-10 remainder over the rearranged, letter-expanded IBAN.
     * A valid IBAN yields a remainder of 1.
     */
    private static function mod97(string $iban): int
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $numeric = '';
        foreach (str_split($rearranged) as $character) {
            $numeric .= ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;
        }

        $remainder = 0;
        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) (($remainder.$chunk) % 97);
        }

        return $remainder;
    }
}
