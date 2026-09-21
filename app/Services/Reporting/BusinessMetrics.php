<?php

namespace App\Services\Reporting;

use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The single source of year-to-date business numbers, so the personal and the
 * workspace dashboards always agree. For the fiscal year:
 *
 * - revenueNet: invoice subtotals (sent, paid, overdue) issued in the year
 * - vatCollected: VAT of the same invoices
 * - expensesGross / deductibleExpenses: confirmed expenses dated in the year
 * - profit: revenueNet − deductibleExpenses (the tax basis)
 * - cashReceived: invoice payments received in the year
 * - receivablesOpen: totals of sent and overdue invoices (any year)
 * - receivablesOverdue: issued, unpaid invoices due strictly before today
 * - pendingExpenses: count and gross of expenses awaiting review in the year
 *
 * Everything is computed with three grouped queries, whatever the number of
 * businesses.
 */
class BusinessMetrics
{
    public function forEntity(BusinessEntity $entity, ?int $year = null): BusinessMetricsResult
    {
        return $this->forEntityIds([$entity->getKey()], $year)->get($entity->getKey()) ?? new BusinessMetricsResult;
    }

    /**
     * The figures summed over all businesses the user owns.
     */
    public function forUser(User $user, ?int $year = null): BusinessMetricsResult
    {
        return $this->perEntity($user, $year)->reduce(
            fn (BusinessMetricsResult $sum, BusinessMetricsResult $metrics): BusinessMetricsResult => $sum->plus($metrics),
            new BusinessMetricsResult,
        );
    }

    /**
     * The figures of every business the user owns, keyed by business id and
     * ordered by business name.
     *
     * @return Collection<string, BusinessMetricsResult>
     */
    public function perEntity(User $user, ?int $year = null): Collection
    {
        $ids = $user->ownedEntities()->orderBy('name')->pluck('id')->all();

        return $this->forEntityIds($ids, $year);
    }

    /**
     * @param  list<string>  $ids
     * @return Collection<string, BusinessMetricsResult>
     */
    public function forEntityIds(array $ids, ?int $year = null): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $year ??= self::currentYear();
        $yearStart = Carbon::create($year, 1, 1)->toDateString();
        $nextYearStart = Carbon::create($year + 1, 1, 1)->toDateString();
        $today = Carbon::today()->toDateString();

        $revenueStatuses = [InvoiceStatus::Sent->value, InvoiceStatus::Paid->value, InvoiceStatus::Overdue->value];
        $revenueIn = implode(', ', array_fill(0, count($revenueStatuses), '?'));
        $unpaidStatuses = array_column(InvoiceStatus::unpaidIssued(), 'value');
        $unpaidIn = implode(', ', array_fill(0, count($unpaidStatuses), '?'));
        $inYear = 'issue_date >= ? and issue_date < ?';

        $invoices = Invoice::query()
            ->whereIn('business_entity_id', $ids)
            ->groupBy('business_entity_id')
            ->selectRaw('business_entity_id')
            ->selectRaw("sum(case when status in ({$revenueIn}) and {$inYear} then subtotal else 0 end) as revenue_net", [...$revenueStatuses, $yearStart, $nextYearStart])
            ->selectRaw("sum(case when status in ({$revenueIn}) and {$inYear} then vat_amount else 0 end) as vat_collected", [...$revenueStatuses, $yearStart, $nextYearStart])
            ->selectRaw("sum(case when status in ({$unpaidIn}) then total else 0 end) as receivables_open", $unpaidStatuses)
            // One definition of overdue: issued, unpaid and due strictly before
            // today (see Invoice::scopeOverdue()).
            ->selectRaw("sum(case when status in ({$unpaidIn}) and due_date < ? then total else 0 end) as receivables_overdue", [...$unpaidStatuses, $today])
            ->get()
            ->keyBy('business_entity_id');

        $expenses = Expense::query()
            ->whereIn('business_entity_id', $ids)
            ->where('expense_date', '>=', $yearStart)
            ->where('expense_date', '<', $nextYearStart)
            ->groupBy('business_entity_id')
            ->selectRaw('business_entity_id')
            ->selectRaw('sum(case when status = ? then amount else 0 end) as expenses_gross', [ExpenseStatus::Reviewed->value])
            ->selectRaw('sum(case when status = ? then deductible_amount else 0 end) as deductible_expenses', [ExpenseStatus::Reviewed->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as pending_count', [ExpenseStatus::PendingReview->value])
            ->selectRaw('sum(case when status = ? then amount else 0 end) as pending_gross', [ExpenseStatus::PendingReview->value])
            ->get()
            ->keyBy('business_entity_id');

        $payments = InvoicePayment::query()
            ->join('invoices', 'invoices.id', '=', 'invoice_payments.invoice_id')
            ->whereNull('invoices.deleted_at')
            ->whereIn('invoices.business_entity_id', $ids)
            ->where('invoice_payments.paid_at', '>=', $yearStart)
            ->where('invoice_payments.paid_at', '<', $nextYearStart)
            ->groupBy('invoices.business_entity_id')
            ->selectRaw('invoices.business_entity_id as business_entity_id, sum(invoice_payments.amount) as cash_received')
            ->toBase()
            ->get()
            ->keyBy('business_entity_id');

        return collect($ids)->mapWithKeys(fn (string $id): array => [
            $id => BusinessMetricsResult::fromAggregates([
                'revenue_net' => $invoices->get($id)?->getAttribute('revenue_net'),
                'vat_collected' => $invoices->get($id)?->getAttribute('vat_collected'),
                'receivables_open' => $invoices->get($id)?->getAttribute('receivables_open'),
                'receivables_overdue' => $invoices->get($id)?->getAttribute('receivables_overdue'),
                'expenses_gross' => $expenses->get($id)?->getAttribute('expenses_gross'),
                'deductible_expenses' => $expenses->get($id)?->getAttribute('deductible_expenses'),
                'pending_count' => $expenses->get($id)?->getAttribute('pending_count'),
                'pending_gross' => $expenses->get($id)?->getAttribute('pending_gross'),
                'cash_received' => $payments->get($id)?->cash_received,
            ]),
        ]);
    }

    public static function currentYear(): int
    {
        return (int) config('settlo.current_fiscal_year', now()->year);
    }
}
