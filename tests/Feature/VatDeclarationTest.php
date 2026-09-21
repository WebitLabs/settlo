<?php

use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Filament\Workspace\Pages\VatDeclaration;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Services\Reporting\VatReturn;
use App\Services\Reporting\VatReturnPeriod;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    [$this->owner, $this->entity] = workspaceOwner('pro');
    $this->entity->forceFill(['mwst_number' => 'CHE-105.829.940 MWST'])->save();

    actAsWorkspace($this->owner, $this->entity);
});

/**
 * An issued invoice with one line at the given rate.
 */
function issuedInvoice(string $number, string $issueDate, float $unitPrice, float $rate): Invoice
{
    $invoice = Invoice::factory()->for(test()->entity, 'businessEntity')->create([
        'invoice_number' => $number,
        'status' => InvoiceStatus::Sent,
        'issue_date' => $issueDate,
    ]);
    InvoiceLineItem::factory()->for($invoice)->create([
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'vat_rate' => $rate,
    ]);

    return $invoice;
}

it('sums output VAT per rate for the selected period', function () {
    issuedInvoice('INV-2026-0001', '2026-02-10', 1000, 8.1);
    issuedInvoice('INV-2026-0002', '2026-03-20', 500, 2.6);
    // Outside Q1 — must not be counted.
    issuedInvoice('INV-2026-0003', '2026-04-01', 9999, 8.1);
    // A draft was never issued.
    Invoice::factory()->draft()->for($this->entity, 'businessEntity')
        ->create(['invoice_number' => 'INV-2026-0004', 'issue_date' => '2026-02-15']);

    $return = app(VatReturn::class)->forPeriod($this->entity, VatReturnPeriod::make(2026, 'q1'));

    expect($return->turnoverNet)->toBe('1500.00')
        ->and($return->outputVat)->toBe('94.00') // 81.00 + 13.00
        ->and($return->invoiceCount)->toBe(2)
        ->and($return->outputRows)->toBe([
            ['rate' => '8.1', 'base' => '1000.00', 'vat' => '81.00'],
            ['rate' => '2.6', 'base' => '500.00', 'vat' => '13.00'],
        ]);
});

it('deducts input VAT from confirmed expenses and computes the net payable', function () {
    issuedInvoice('INV-2026-0001', '2026-02-10', 1000, 8.1);

    Expense::factory()->for($this->entity, 'businessEntity')->create([
        'status' => ExpenseStatus::Reviewed,
        'expense_date' => '2026-02-20',
        'amount' => 216.20,
        'net_amount' => 200.00,
        'vat_amount' => 16.20,
        'vat_rate' => 8.1,
    ]);
    // Awaiting confirmation → no input tax yet.
    Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')->create([
        'expense_date' => '2026-02-21',
        'amount' => 1000,
        'net_amount' => 925,
        'vat_amount' => 75,
        'vat_rate' => 8.1,
    ]);

    $return = app(VatReturn::class)->forPeriod($this->entity, VatReturnPeriod::make(2026, 'q1'));

    expect($return->inputVat)->toBe('16.20')
        ->and($return->inputBase)->toBe('200.00')
        ->and($return->expenseCount)->toBe(1)
        ->and($return->netPayable)->toBe('64.80')
        ->and($return->isRefund())->toBeFalse();
});

it('reports a refund when input VAT exceeds output VAT', function () {
    Expense::factory()->for($this->entity, 'businessEntity')->create([
        'status' => ExpenseStatus::Reviewed,
        'expense_date' => '2026-02-20',
        'amount' => 1081.00,
        'net_amount' => 1000.00,
        'vat_amount' => 81.00,
        'vat_rate' => 8.1,
    ]);

    $return = app(VatReturn::class)->forPeriod($this->entity, VatReturnPeriod::make(2026, 'q1'));

    expect($return->netPayable)->toBe('-81.00')
        ->and($return->isRefund())->toBeTrue()
        ->and($return->absoluteNetPayable())->toBe('81.00');
});

it('covers quarters, half-years and the full year', function (string $key, string $start, string $end) {
    $period = VatReturnPeriod::make(2026, $key);

    expect($period->start)->toBe($start)
        ->and($period->end)->toBe($end);
})->with([
    'q1' => ['q1', '2026-01-01', '2026-04-01'],
    'q4' => ['q4', '2026-10-01', '2027-01-01'],
    's2' => ['s2', '2026-07-01', '2027-01-01'],
    'year' => ['year', '2026-01-01', '2027-01-01'],
    'unknown falls back to the year' => ['nonsense', '2026-01-01', '2027-01-01'],
]);

it('renders the declaration page with the period figures', function () {
    issuedInvoice('INV-2026-0001', '2026-02-10', 1000, 8.1);

    Livewire::test(VatDeclaration::class)
        ->assertOk()
        ->set('period', 'q1')
        ->set('year', 2026)
        ->assertSee('VAT payable')
        ->assertSee("CHF 1'000.00")
        ->assertSee('CHF 81.00');
});

it('gates the declaration behind the plan feature when gating is enforced', function () {
    config(['settlo.enforce_feature_gates' => true]);

    expect($this->entity->hasFeature(PlanFeature::VatForm300))->toBeTrue()
        ->and(VatDeclaration::canAccess())->toBeTrue();

    // A plan without the feature cannot reach the page at all.
    $this->entity->subscription->plan->forceFill(['features' => []])->save();
    $this->entity->subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
    $this->entity->refresh()->unsetRelation('subscription');

    expect(VatDeclaration::canAccess())->toBeFalse();
});

it('opens the declaration on any plan while gating is off (decision 8a)', function () {
    expect(config('settlo.enforce_feature_gates'))->toBeFalse();

    $this->entity->subscription->plan->forceFill(['features' => []])->save();
    $this->entity->subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
    $this->entity->refresh()->unsetRelation('subscription');

    expect(VatDeclaration::canAccess())->toBeTrue();

    Livewire::test(VatDeclaration::class)->assertOk();
});

it('still closes the declaration on a workspace that may not be written to', function () {
    $this->entity->subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
    $this->entity->refresh()->unsetRelation('subscription');

    expect(VatDeclaration::canAccess())->toBeFalse();
});
