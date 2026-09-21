<?php

use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PlanFeature;
use App\Enums\SubscriptionStatus;
use App\Filament\Workspace\Pages\YearEndExport;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Services\Reporting\YearEndExportService;
use Database\Seeders\ReferenceDataSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    [$this->owner, $this->entity] = workspaceOwner('pro');
    $this->entity->forceFill(['name' => 'Muster Design'])->save();
    $this->client = Client::factory()->for($this->entity, 'businessEntity')->create(['name' => 'Acme AG']);

    actAsWorkspace($this->owner, $this->entity);

    $invoice = Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'invoice_number' => 'INV-2026-0001',
        'client_id' => $this->client->getKey(),
        'status' => InvoiceStatus::Sent,
        'issue_date' => '2026-05-04',
        'due_date' => '2026-06-03',
        'subtotal' => 1000,
        'vat_amount' => 81,
        'total' => 1081,
    ]);
    InvoiceLineItem::factory()->for($invoice)->create(['quantity' => 1, 'unit_price' => 1000, 'vat_rate' => 8.1]);

    Expense::factory()->for($this->entity, 'businessEntity')->create([
        'vendor' => 'SBB CFF FFS',
        'status' => ExpenseStatus::Reviewed,
        'expense_date' => '2026-05-10',
        'amount' => 216.20,
        'net_amount' => 200,
        'vat_amount' => 16.20,
        'vat_rate' => 8.1,
        'deductible_pct' => 100,
        'deductible_amount' => 216.20,
    ]);

    // Last year — never part of this export.
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'invoice_number' => 'INV-2025-0099',
        'status' => InvoiceStatus::Paid,
        'issue_date' => '2025-12-31',
    ]);
});

it('exports every invoice of the year as CSV', function () {
    $csv = app(YearEndExportService::class)->csv($this->entity, 2026, YearEndExportService::INVOICES);

    expect($csv)->toContain('invoice_number,issue_date,due_date,client,status,currency')
        ->toContain('INV-2026-0001,2026-05-04,2026-06-03,"Acme AG",sent,CHF,1000.00,81.00,1081.00')
        ->not->toContain('INV-2025-0099');
});

it('exports every expense of the year with its deductible share', function () {
    $csv = app(YearEndExportService::class)->csv($this->entity, 2026, YearEndExportService::EXPENSES);

    expect($csv)->toContain('expense_date,vendor,category,status,currency')
        ->toContain('2026-05-10,"SBB CFF FFS",')
        ->toContain('216.20');
});

it('exports the totals, agreeing with the dashboard and the VAT declaration', function () {
    $rows = collect(app(YearEndExportService::class)->rows($this->entity, 2026, YearEndExportService::SUMMARY))
        ->mapWithKeys(fn (array $row): array => [$row[0] => $row[1]]);

    expect($rows['Revenue (net of VAT)'])->toBe('1000.00')
        ->and($rows['VAT collected'])->toBe('81.00')
        ->and($rows['Expenses (gross, confirmed)'])->toBe('216.20')
        ->and($rows['Deductible expenses'])->toBe('216.20')
        ->and($rows['Profit (revenue less deductible expenses)'])->toBe('783.80')
        ->and($rows['Output VAT (invoiced)'])->toBe('81.00')
        ->and($rows['Input VAT (confirmed expenses)'])->toBe('16.20')
        ->and($rows['Net VAT payable'])->toBe('64.80');
});

it('names the download after the business and the year', function () {
    expect(app(YearEndExportService::class)->filename($this->entity, 2026, YearEndExportService::INVOICES))
        ->toBe('muster-design-2026-invoices.csv');
});

it('downloads each dataset from the page', function (string $dataset) {
    Livewire::test(YearEndExport::class)
        ->callAction("download_{$dataset}")
        ->assertHasNoActionErrors()
        ->assertFileDownloaded("muster-design-2026-{$dataset}.csv");
})->with(YearEndExportService::DATASETS);

it('shows the counts and the totals on the page', function () {
    Livewire::test(YearEndExport::class)
        ->assertOk()
        ->assertSee('Year-end export')
        ->assertSee('Revenue (net of VAT)')
        ->assertSee("CHF 1'000.00")
        ->assertSee('Net VAT payable');
});

it('gates the export behind the plan feature when gating is enforced', function () {
    config(['settlo.enforce_feature_gates' => true]);

    expect($this->entity->hasFeature(PlanFeature::YearEndExport))->toBeTrue()
        ->and(YearEndExport::canAccess())->toBeTrue();

    $this->entity->subscription->plan->forceFill(['features' => []])->save();
    $this->entity->subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
    $this->entity->refresh()->unsetRelation('subscription');

    expect(YearEndExport::canAccess())->toBeFalse();
});

it('opens the export on any plan while gating is off (decision 8a)', function () {
    expect(config('settlo.enforce_feature_gates'))->toBeFalse();

    $this->entity->subscription->plan->forceFill(['features' => []])->save();
    $this->entity->subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
    $this->entity->refresh()->unsetRelation('subscription');

    expect(YearEndExport::canAccess())->toBeTrue();

    Livewire::test(YearEndExport::class)->assertOk();
});

it('still closes the export on a workspace that may not be written to', function () {
    $this->entity->subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
    $this->entity->refresh()->unsetRelation('subscription');

    expect(YearEndExport::canAccess())->toBeFalse();
});
