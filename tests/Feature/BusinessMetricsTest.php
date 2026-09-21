<?php

use App\Enums\InvoiceStatus;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use App\Services\Reporting\BusinessMetrics;
use App\Services\Reporting\BusinessMetricsResult;
use App\Services\Tax\TaxEngine;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed one business with a representative mix of invoices, payments and
 * expenses for 2026 (plus noise from 2025 and a draft).
 */
function seedMetricsData(BusinessEntity $entity): void
{
    $invoice = fn (array $attributes): Invoice => Invoice::factory()->for($entity, 'businessEntity')->create($attributes);

    $paid = $invoice(['status' => InvoiceStatus::Paid, 'subtotal' => 1000, 'vat_amount' => 81, 'total' => 1081, 'issue_date' => '2026-02-01', 'due_date' => '2026-03-01']);
    $invoice(['status' => InvoiceStatus::Sent, 'subtotal' => 2000, 'vat_amount' => 162, 'total' => 2162, 'issue_date' => '2026-08-01', 'due_date' => '2026-10-01']);
    $invoice(['status' => InvoiceStatus::Sent, 'subtotal' => 500, 'vat_amount' => 0, 'total' => 500, 'issue_date' => '2026-06-01', 'due_date' => '2026-07-01']);
    $invoice(['status' => InvoiceStatus::Overdue, 'subtotal' => 300.50, 'vat_amount' => 0, 'total' => 300.50, 'issue_date' => '2026-05-01', 'due_date' => '2026-06-01']);
    $invoice(['status' => InvoiceStatus::Draft, 'subtotal' => 9999, 'vat_amount' => 0, 'total' => 9999, 'issue_date' => '2026-08-01', 'due_date' => '2026-09-01']);
    $invoice(['status' => InvoiceStatus::Paid, 'subtotal' => 7000, 'vat_amount' => 0, 'total' => 7000, 'issue_date' => '2025-12-31', 'due_date' => '2026-01-30']);

    InvoicePayment::create(['invoice_id' => $paid->getKey(), 'amount' => 1081, 'paid_at' => '2026-03-01']);
    InvoicePayment::create(['invoice_id' => $paid->getKey(), 'amount' => 50, 'paid_at' => '2025-12-20']);

    $expense = fn (array $attributes, bool $pending = false): Expense => ($pending ? Expense::factory()->pendingReview() : Expense::factory())
        ->for($entity, 'businessEntity')
        ->create($attributes);

    $expense(['amount' => 200, 'deductible_amount' => 200, 'expense_date' => '2026-03-15']);
    $expense(['amount' => 100, 'deductible_amount' => 50, 'expense_date' => '2026-12-31']);
    $expense(['amount' => 999, 'deductible_amount' => 999, 'expense_date' => '2025-12-31']);
    $expense(['amount' => 80, 'expense_date' => '2026-04-01'], pending: true);
    $expense(['amount' => 20.25, 'expense_date' => '2026-04-02'], pending: true);
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->travelTo('2026-09-17 10:00:00');
    config(['settlo.current_fiscal_year' => 2026]);

    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['name' => 'Alpha']);
});

it('computes the year-to-date figures of one business', function () {
    seedMetricsData($this->entity);

    $metrics = app(BusinessMetrics::class)->forEntity($this->entity);

    expect($metrics)->toBeInstanceOf(BusinessMetricsResult::class)
        ->and($metrics->revenueNet)->toBe('3800.50')
        ->and($metrics->vatCollected)->toBe('243.00')
        ->and($metrics->expensesGross)->toBe('300.00')
        ->and($metrics->deductibleExpenses)->toBe('250.00')
        ->and($metrics->profit)->toBe('3550.50')
        ->and($metrics->cashReceived)->toBe('1081.00')
        ->and($metrics->receivablesOpen)->toBe('2962.50')
        ->and($metrics->receivablesOverdue)->toBe('800.50')
        ->and($metrics->pendingExpensesCount)->toBe(2)
        ->and($metrics->pendingExpensesGross)->toBe('100.25');
});

it('returns zeros for a business without data', function () {
    $metrics = app(BusinessMetrics::class)->forEntity($this->entity);

    expect($metrics->revenueNet)->toBe('0.00')
        ->and($metrics->profit)->toBe('0.00')
        ->and($metrics->pendingExpensesCount)->toBe(0);
});

it('uses the requested fiscal year', function () {
    seedMetricsData($this->entity);

    $metrics = app(BusinessMetrics::class)->forEntity($this->entity, 2025);

    expect($metrics->revenueNet)->toBe('7000.00')
        ->and($metrics->deductibleExpenses)->toBe('999.00')
        ->and($metrics->cashReceived)->toBe('50.00');
});

it('sums and splits the figures over the owner businesses without N+1 queries', function () {
    $second = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['name' => 'Beta']);
    $foreign = BusinessEntity::factory()->forCanton('ZH')->create();
    seedMetricsData($this->entity);
    seedMetricsData($second);
    seedMetricsData($foreign);

    DB::enableQueryLog();
    $perEntity = app(BusinessMetrics::class)->perEntity($this->owner);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($perEntity->keys()->all())->toBe([$this->entity->getKey(), $second->getKey()])
        ->and($queries)->toBe(4)
        ->and($perEntity[$second->getKey()]->revenueNet)->toBe('3800.50');

    $total = app(BusinessMetrics::class)->forUser($this->owner);

    expect($total->revenueNet)->toBe('7601.00')
        ->and($total->profit)->toBe('7101.00')
        ->and($total->pendingExpensesCount)->toBe(4);
});

it('agrees with the tax engine on revenue and profit', function () {
    seedMetricsData($this->entity);

    $estimation = app(TaxEngine::class)->estimateFor($this->entity);
    $metrics = app(BusinessMetrics::class)->forEntity($this->entity);

    expect((float) $estimation->gross_revenue)->toBe((float) $metrics->revenueNet)
        ->and((float) $estimation->net_income)->toBe((float) $metrics->profit);
});

it('ignores soft-deleted invoices', function () {
    seedMetricsData($this->entity);
    $this->entity->invoices()->where('subtotal', 2000)->first()->delete();

    expect(app(BusinessMetrics::class)->forEntity($this->entity)->revenueNet)->toBe('1800.50');
});
