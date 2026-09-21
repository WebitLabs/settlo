<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A Swiss VAT number: a valid UID followed by an optional MWST/TVA/IVA suffix
 * ("CHE-123.456.788 MWST"). A number typed without a suffix gets " MWST".
 */
class SwissVatNumber implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::normalize((string) $value) === null) {
            $fail('Enter a valid Swiss VAT number, e.g. CHE-123.456.788 MWST.');
        }
    }

    /**
     * The canonical "CHE-123.456.789 MWST" form, or null when invalid. The
     * typed suffix is kept; "MWST" is used when none was typed.
     */
    public static function normalize(string $vatNumber): ?string
    {
        if (preg_match('/^\s*(.+?)\s*(MWST|TVA|IVA)?\s*$/i', $vatNumber, $matches) !== 1) {
            return null;
        }

        $uid = SwissUid::normalize($matches[1]);

        if ($uid === null) {
            return null;
        }

        return $uid.' '.strtoupper(filled($matches[2] ?? null) ? $matches[2] : 'MWST');
    }
}
