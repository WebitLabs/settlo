<?php

namespace App\Services\Reporting;

/**
 * The figures of one VAT return (Swiss Form 300), as BCMath strings with two
 * decimals. `netPayable` is negative when the input tax exceeds the output tax
 * — i.e. the AFC owes the business a refund.
 *
 * @phpstan-type VatRow array{rate: string, base: string, vat: string}
 */
final readonly class VatReturnResult
{
    /**
     * @param  list<array{rate: string, base: string, vat: string}>  $outputRows  Turnover per VAT rate, highest rate first.
     * @param  list<array{rate: string, base: string, vat: string}>  $inputRows  Purchases per VAT rate, highest rate first.
     */
    public function __construct(
        public VatReturnPeriod $period,
        public string $turnoverNet = '0.00',
        public string $outputVat = '0.00',
        public string $inputBase = '0.00',
        public string $inputVat = '0.00',
        public string $netPayable = '0.00',
        public array $outputRows = [],
        public array $inputRows = [],
        public int $invoiceCount = 0,
        public int $expenseCount = 0,
    ) {}

    /**
     * True when the return results in a refund rather than a payment.
     */
    public function isRefund(): bool
    {
        return bccomp($this->netPayable, '0', 2) < 0;
    }

    /**
     * The amount due, always positive — the direction is `isRefund()`.
     */
    public function absoluteNetPayable(): string
    {
        return ltrim($this->netPayable, '-');
    }

    public function hasData(): bool
    {
        return $this->invoiceCount > 0 || $this->expenseCount > 0;
    }
}
