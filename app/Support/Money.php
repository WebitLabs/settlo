<?php

namespace App\Support;

/**
 * The one money formatter of the app. Swiss notation throughout: an apostrophe
 * as the thousands separator and a dot as the decimal separator, with the
 * currency code in front ("CHF 1'081.49"). Forms, tables, infolists, widgets
 * and the invoice PDF all go through here, so the same amount never renders in
 * two different shapes.
 */
final class Money
{
    public const string THOUSANDS_SEPARATOR = "'";

    public const string DEFAULT_CURRENCY = 'CHF';

    /**
     * The bare number, without a currency ("1'081.49").
     */
    public static function number(mixed $value, int $decimals = 2): string
    {
        return number_format(self::toFloat($value), $decimals, '.', self::THOUSANDS_SEPARATOR);
    }

    /**
     * The amount with its currency ("CHF 1'081.49").
     */
    public static function format(mixed $value, ?string $currency = null, int $decimals = 2): string
    {
        return trim(($currency ?: self::DEFAULT_CURRENCY).' '.self::number($value, $decimals));
    }

    /**
     * The amount with its currency, rounded to whole francs ("CHF 1'081") —
     * the headline form used by the dashboard stat widgets.
     */
    public static function rounded(mixed $value, ?string $currency = null): string
    {
        return self::format($value, $currency, decimals: 0);
    }

    /**
     * A quantity or percentage without trailing zeros ("2", "2.5", "8.1").
     */
    public static function trimmed(mixed $value, int $decimals = 2): string
    {
        $formatted = rtrim(rtrim(number_format(self::toFloat($value), $decimals, '.', ''), '0'), '.');

        return in_array($formatted, ['', '-0'], true) ? '0' : $formatted;
    }

    private static function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
