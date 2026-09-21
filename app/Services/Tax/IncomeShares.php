<?php

namespace App\Services\Tax;

/**
 * Splits the owner's personal tax across their sole proprietorships in
 * proportion to each business' positive net income. Loss-making businesses
 * get a zero share. Rounded shares always add up to exactly 100 %: the
 * rounding remainder is assigned to the business with the largest income.
 */
final class IncomeShares
{
    private const int SCALE = 6;

    /**
     * Each business' share as a decimal fraction at scale 6 ("0.600000"),
     * or "0" for a business without positive income.
     *
     * @param  array<array-key, float|int|string>  $netIncomes  net income keyed by business id
     * @return array<array-key, string>
     */
    public static function fractions(array $netIncomes): array
    {
        return self::allocate($netIncomes, '1', self::SCALE);
    }

    /**
     * Each business' share in percent, rounded to $decimals (33.4).
     *
     * @param  array<array-key, float|int|string>  $netIncomes  net income keyed by business id
     * @return array<array-key, float>
     */
    public static function percentages(array $netIncomes, int $decimals = 1): array
    {
        return array_map(
            fn (string $share): float => (float) $share,
            self::allocate($netIncomes, '100', $decimals),
        );
    }

    /**
     * @param  array<array-key, float|int|string>  $netIncomes
     * @return array<array-key, string>
     */
    private static function allocate(array $netIncomes, string $whole, int $scale): array
    {
        $positive = array_map(
            fn (float|int|string $income): string => bccomp(self::decimal($income), '0', 2) > 0 ? self::decimal($income) : '0',
            $netIncomes,
        );

        $total = array_reduce($positive, fn (string $sum, string $income): string => bcadd($sum, $income, 2), '0');

        if (bccomp($total, '0', 2) <= 0) {
            return array_map(fn (): string => '0', $netIncomes);
        }

        $shares = [];
        $allocated = '0';
        $largestKey = null;

        foreach ($positive as $key => $income) {
            if (bccomp($income, '0', 2) <= 0) {
                $shares[$key] = '0';

                continue;
            }

            $shares[$key] = bcround(bcdiv(bcmul($income, $whole, self::SCALE + 2), $total, $scale + 1), $scale);
            $allocated = bcadd($allocated, $shares[$key], $scale);

            if ($largestKey === null || bccomp($income, $positive[$largestKey], 2) > 0) {
                $largestKey = $key;
            }
        }

        $shares[$largestKey] = bcadd($shares[$largestKey], bcsub($whole, $allocated, $scale), $scale);

        return $shares;
    }

    private static function decimal(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
