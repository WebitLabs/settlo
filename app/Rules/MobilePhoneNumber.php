<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use libphonenumber\PhoneNumberType;
use Propaganistas\LaravelPhone\PhoneNumber;
use Throwable;

/**
 * A valid mobile phone number of the given country (libphonenumber). Numbers
 * may be entered in national format or with the international prefix.
 */
class MobilePhoneNumber implements ValidationRule
{
    public function __construct(private readonly string $country = 'CH') {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid((string) $value, $this->country)) {
            $fail('Enter a valid mobile phone number for the selected country.');
        }
    }

    public static function isValid(string $number, string $country): bool
    {
        try {
            $phone = new PhoneNumber($number, $country);

            return $phone->isValid()
                && $phone->isOfCountry($country)
                && $phone->isOfType([PhoneNumberType::MOBILE, PhoneNumberType::FIXED_LINE_OR_MOBILE]);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The E.164 form ("+41791234567"), or null when the number can't be parsed.
     */
    public static function toE164(?string $number, string $country): ?string
    {
        if (blank($number)) {
            return null;
        }

        try {
            return (new PhoneNumber($number, $country))->formatE164();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The national display form of a stored E.164 number (falls back to the input).
     */
    public static function toNational(?string $number): ?string
    {
        if (blank($number)) {
            return null;
        }

        try {
            return (new PhoneNumber($number))->formatNational();
        } catch (Throwable) {
            return $number;
        }
    }
}
