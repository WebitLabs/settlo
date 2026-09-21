<?php

namespace App\Support;

use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use Locale;

/**
 * Country dial codes for the phone number picker: Switzerland and its
 * neighbours first, then every region libphonenumber supports, by name.
 */
final class PhoneCountries
{
    /**
     * Regions listed first, in this order.
     *
     * @var list<string>
     */
    private const array PREFERRED = ['CH', 'LI', 'DE', 'AT', 'FR', 'IT'];

    /**
     * @var array<string, string>|null
     */
    private static ?array $options = null;

    /**
     * Options keyed by ISO 3166-1 alpha-2 code, e.g. "CH" => "🇨🇭 Switzerland +41".
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        if (self::$options !== null) {
            return self::$options;
        }

        $util = PhoneNumberUtil::getInstance();

        $others = collect($util->getSupportedRegions())
            ->reject(fn (string $iso): bool => in_array($iso, self::PREFERRED, true))
            ->mapWithKeys(fn (string $iso): array => [$iso => self::label($iso)])
            ->sortBy(fn (string $label, string $iso): string => self::name($iso))
            ->all();

        $preferred = collect(self::PREFERRED)
            ->mapWithKeys(fn (string $iso): array => [$iso => self::label($iso)])
            ->all();

        return self::$options = [...$preferred, ...$others];
    }

    /**
     * A sample mobile number in national format, used as placeholder.
     */
    public static function example(string $iso): string
    {
        $util = PhoneNumberUtil::getInstance();
        $example = $util->getExampleNumberForType(strtoupper($iso), PhoneNumberType::MOBILE);

        return $example === null ? '' : $util->format($example, PhoneNumberFormat::NATIONAL);
    }

    /**
     * Whether the code is a region libphonenumber knows.
     */
    public static function isSupported(string $iso): bool
    {
        return array_key_exists(strtoupper($iso), self::options());
    }

    private static function label(string $iso): string
    {
        $code = PhoneNumberUtil::getInstance()->getCountryCodeForRegion($iso);

        return sprintf('%s %s +%d', self::flag($iso), self::name($iso), $code);
    }

    private static function name(string $iso): string
    {
        $name = Locale::getDisplayRegion('-'.$iso, 'en');

        return $name !== '' && $name !== false ? $name : $iso;
    }

    /**
     * The flag emoji: the two regional-indicator characters of the code.
     */
    private static function flag(string $iso): string
    {
        return implode('', array_map(
            fn (string $letter): string => mb_chr(0x1F1E6 + ord($letter) - 65),
            str_split(strtoupper($iso)),
        ));
    }
}
