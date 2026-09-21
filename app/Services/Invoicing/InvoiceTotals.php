<?php

namespace App\Services\Invoicing;

/**
 * Pure BCMath invoice arithmetic shared by the live form preview and the
 * persisted totals, so what the user sees while typing is exactly what is
 * saved. Every amount is a decimal string rounded to CHF 0.01 per line.
 */
final class InvoiceTotals
{
    private const int SCALE = 6;

    /**
     * Coerce user input into a BCMath-safe decimal string; anything that is not
     * a number (empty input, text) counts as zero.
     */
    public static function numeric(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0';
        }

        $value = trim((string) $value);

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) === 1) {
            return $value;
        }

        return sprintf('%.10F', (float) $value);
    }

    /**
     * Round a BCMath decimal string half away from zero (CHF 0.01 by default).
     */
    public static function round(string $value, int $scale = 2): string
    {
        $value = self::numeric($value);
        $factor = bcpow('10', (string) $scale);
        $shifted = bcmul($value, $factor, 1);
        $adjust = str_starts_with($value, '-') ? '-0.5' : '0.5';

        return bcdiv(bcadd($shifted, $adjust, 0), $factor, $scale);
    }

    /**
     * Net amount of one line: quantity × unit price, rounded to CHF 0.01.
     */
    public static function lineNet(mixed $quantity, mixed $unitPrice): string
    {
        return self::round(bcmul(self::numeric($quantity), self::numeric($unitPrice), self::SCALE));
    }

    /**
     * VAT on a line's net amount at the given percentage rate, rounded to CHF 0.01.
     */
    public static function lineVat(string $net, mixed $rate): string
    {
        return self::round(bcdiv(bcmul(self::numeric($net), self::numeric($rate), self::SCALE), '100', self::SCALE));
    }

    /**
     * Compact VAT rate string used as a grouping key ("8.10" → "8.1", "0.00" → "0").
     */
    public static function normalizeRate(mixed $rate): string
    {
        $trimmed = rtrim(rtrim(number_format((float) self::numeric($rate), 2, '.', ''), '0'), '.');

        return in_array($trimmed, ['', '-0'], true) ? '0' : $trimmed;
    }

    /**
     * Subtotal, VAT and total for a set of lines, plus the per-rate breakdown
     * (highest rate first). Lines may be arrays (form state) or models.
     *
     * @param  iterable<mixed>  $lines
     * @return array{subtotal: string, vat: string, total: string, breakdown: array<string, array{rate: string, base: string, vat: string}>}
     */
    public static function forLines(iterable $lines): array
    {
        $subtotal = '0.00';
        $vatTotal = '0.00';
        $breakdown = [];

        foreach ($lines as $line) {
            $net = self::lineNet(data_get($line, 'quantity'), data_get($line, 'unit_price'));
            $vat = self::lineVat($net, data_get($line, 'vat_rate'));
            $rate = self::normalizeRate(data_get($line, 'vat_rate'));

            $breakdown[$rate] ??= ['rate' => $rate, 'base' => '0.00', 'vat' => '0.00'];
            $breakdown[$rate]['base'] = bcadd($breakdown[$rate]['base'], $net, 2);
            $breakdown[$rate]['vat'] = bcadd($breakdown[$rate]['vat'], $vat, 2);

            $subtotal = bcadd($subtotal, $net, 2);
            $vatTotal = bcadd($vatTotal, $vat, 2);
        }

        krsort($breakdown, SORT_NUMERIC);

        return [
            'subtotal' => $subtotal,
            'vat' => $vatTotal,
            'total' => bcadd($subtotal, $vatTotal, 2),
            'breakdown' => $breakdown,
        ];
    }
}
