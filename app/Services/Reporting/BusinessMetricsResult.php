<?php

namespace App\Services\Reporting;

/**
 * Year-to-date business figures (BCMath strings with two decimals) for one
 * business or summed over several. See BusinessMetrics for the definitions.
 */
final readonly class BusinessMetricsResult
{
    public function __construct(
        public string $revenueNet = '0.00',
        public string $vatCollected = '0.00',
        public string $expensesGross = '0.00',
        public string $deductibleExpenses = '0.00',
        public string $profit = '0.00',
        public string $cashReceived = '0.00',
        public string $receivablesOpen = '0.00',
        public string $receivablesOverdue = '0.00',
        public int $pendingExpensesCount = 0,
        public string $pendingExpensesGross = '0.00',
    ) {}

    /**
     * Build a result from raw aggregate values; the profit is derived.
     *
     * @param  array{revenue_net?: mixed, vat_collected?: mixed, expenses_gross?: mixed, deductible_expenses?: mixed, cash_received?: mixed, receivables_open?: mixed, receivables_overdue?: mixed, pending_count?: mixed, pending_gross?: mixed}  $values
     */
    public static function fromAggregates(array $values): self
    {
        $revenueNet = self::money($values['revenue_net'] ?? 0);
        $deductible = self::money($values['deductible_expenses'] ?? 0);

        return new self(
            revenueNet: $revenueNet,
            vatCollected: self::money($values['vat_collected'] ?? 0),
            expensesGross: self::money($values['expenses_gross'] ?? 0),
            deductibleExpenses: $deductible,
            profit: bcsub($revenueNet, $deductible, 2),
            cashReceived: self::money($values['cash_received'] ?? 0),
            receivablesOpen: self::money($values['receivables_open'] ?? 0),
            receivablesOverdue: self::money($values['receivables_overdue'] ?? 0),
            pendingExpensesCount: (int) ($values['pending_count'] ?? 0),
            pendingExpensesGross: self::money($values['pending_gross'] ?? 0),
        );
    }

    /**
     * The sum of this result and another one.
     */
    public function plus(self $other): self
    {
        return new self(
            revenueNet: bcadd($this->revenueNet, $other->revenueNet, 2),
            vatCollected: bcadd($this->vatCollected, $other->vatCollected, 2),
            expensesGross: bcadd($this->expensesGross, $other->expensesGross, 2),
            deductibleExpenses: bcadd($this->deductibleExpenses, $other->deductibleExpenses, 2),
            profit: bcadd($this->profit, $other->profit, 2),
            cashReceived: bcadd($this->cashReceived, $other->cashReceived, 2),
            receivablesOpen: bcadd($this->receivablesOpen, $other->receivablesOpen, 2),
            receivablesOverdue: bcadd($this->receivablesOverdue, $other->receivablesOverdue, 2),
            pendingExpensesCount: $this->pendingExpensesCount + $other->pendingExpensesCount,
            pendingExpensesGross: bcadd($this->pendingExpensesGross, $other->pendingExpensesGross, 2),
        );
    }

    /**
     * Normalise a database aggregate (string, int, float or null) to a
     * two-decimal BCMath string.
     */
    public static function money(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.00';
        }

        $value = trim((string) $value);

        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $value) !== 1) {
            $value = sprintf('%.2F', round((float) $value, 2));
        }

        return bcadd($value, '0', 2);
    }
}
