<?php

use App\Enums\BusinessEntityType;
use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Jobs\RecalculatePersonalTaxEstimation;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\TaxEstimation;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Tax\IncomeShares;
use App\Services\Tax\TaxCalculator;
use App\Services\Tax\TaxEngine;
use App\Services\Tax\TaxInput;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->seed(DemoSeeder::class);
});

it('persists an estimation snapshot for the demo entity matching the fixture', function () {
    $entity = User::where('email', 'anna@test.ch')->first()->ownedEntities()->first();

    $estimation = app(TaxEngine::class)->estimateFor($entity, 2026);

    // Anna's real profile (revenue 68,400, deductible 14,200, ZH, 3a 7,056)
    // reproduces the canonical total tax burden.
    expect((float) $estimation->total_tax_burden)->toBe(14825.05)
        ->and((float) $estimation->monthly_reserve)->toBe(1235.42)
        ->and($estimation->vat_alert_level)->toBe('info') // 68.4% of threshold
        ->and($estimation->rates_snapshot['canton_code'])->toBe('ZH');
});

it('writes an immutable snapshot on each recalculation rather than mutating', function () {
    $entity = User::where('email', 'anna@test.ch')->first()->ownedEntities()->first();
    $engine = app(TaxEngine::class);

    // The demo fixture already carries an estimation, so count the rows this
    // test adds rather than the absolute total.
    $before = $entity->taxEstimations()->count();

    $first = $engine->estimateFor($entity, 2026);
    $second = $engine->estimateFor($entity, 2026);

    expect($second->id)->not->toBe($first->id)
        ->and($entity->taxEstimations()->count())->toBe($before + 2);
});

it('counts net invoice amounts (excluding VAT) as revenue', function () {
    $entity = BusinessEntity::factory()->forCanton('ZH')->create();
    Invoice::factory()->for($entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'subtotal' => 1000,
        'vat_amount' => 81,
        'total' => 1081,
        'issue_date' => '2026-03-01',
    ]);

    $estimation = app(TaxEngine::class)->estimateFor($entity, 2026);

    expect((float) $estimation->gross_revenue)->toBe(1000.0);
});

describe('consolidated personal estimate (B3)', function () {
    beforeEach(function () {
        $this->owner = User::factory()->owner()->create();
        TaxProfile::factory()->forCanton('ZH')->for($this->owner)->create([
            'pillar3a_amount' => 0,
            'birth_year' => 1990,
        ]);

        $this->revenue = function (BusinessEntity $entity, float $amount): void {
            Invoice::factory()->for($entity, 'businessEntity')->create([
                'status' => InvoiceStatus::Sent,
                'subtotal' => $amount,
                'vat_amount' => 0,
                'total' => $amount,
                'issue_date' => '2026-03-01',
            ]);
        };
    });

    it('taxes the combined income of all sole proprietorships once and splits it by net income', function () {
        $first = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['created_at' => now()->subDay()]);
        $second = BusinessEntity::factory()->forCanton('BE')->for($this->owner, 'owner')->create();
        ($this->revenue)($first, 60000);
        ($this->revenue)($second, 40000);

        $engine = app(TaxEngine::class);
        $personal = $engine->estimateAllFor($this->owner, 2026);

        $expected = app(TaxCalculator::class)->calculate(new TaxInput(
            cantonCode: 'ZH',
            fiscalYear: 2026,
            grossRevenue: 100000,
            deductibleExpenses: 0,
            age: 36,
            daysElapsed: $personal->inputs['days_elapsed'],
        ));

        expect($personal->business_entity_id)->toBeNull()
            ->and($personal->user_id)->toBe($this->owner->getKey())
            ->and((float) $personal->gross_revenue)->toBe(100000.0)
            ->and((float) $personal->total_tax_burden)->toBe($expected->totalTaxBurden)
            ->and($personal->inputs['businesses'])->toHaveKeys([$first->getKey(), $second->getKey()])
            ->and($this->owner->personalTaxEstimation(2026)->getKey())->toBe($personal->getKey());

        $firstRow = $first->latestTaxEstimation(2026);
        $secondRow = $second->latestTaxEstimation(2026);

        expect($firstRow->inputs['share'])->toBe('0.600000')
            ->and($secondRow->inputs['share'])->toBe('0.400000')
            ->and($firstRow->inputs['personal_estimation_id'])->toBe($personal->getKey())
            ->and((float) $firstRow->gross_revenue)->toBe(60000.0)
            ->and((float) $secondRow->gross_revenue)->toBe(40000.0)
            ->and((float) $firstRow->total_tax_burden)->toBe(round($expected->totalTaxBurden * 0.6, 2))
            ->and((float) $secondRow->total_tax_burden)->toBe(round($expected->totalTaxBurden * 0.4, 2))
            // Exactly one personal estimation for this owner (the demo fixture
            // owns one of its own, so scope the count to them).
            ->and(TaxEstimation::whereNull('business_entity_id')
                ->where('user_id', $this->owner->getKey())
                ->count())->toBe(1);
    });

    it('gives a loss-making business a zero share', function () {
        $profitable = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
        $lossMaking = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
        ($this->revenue)($profitable, 50000);
        Expense::factory()->for($lossMaking, 'businessEntity')->create([
            'status' => ExpenseStatus::Reviewed,
            'expense_date' => '2026-02-01',
            'deductible_amount' => 5000,
        ]);

        app(TaxEngine::class)->estimateAllFor($this->owner, 2026);

        $lossRow = $lossMaking->latestTaxEstimation(2026);
        $profitRow = $profitable->latestTaxEstimation(2026);

        expect($lossRow->inputs['share'])->toBe('0')
            ->and((float) $lossRow->total_tax_burden)->toBe(0.0)
            ->and((float) $lossRow->net_income)->toBe(-5000.0)
            ->and($profitRow->inputs['share'])->toBe('1.000000')
            ->and((float) $profitRow->total_tax_burden)->toBe((float) $this->owner->personalTaxEstimation(2026)->total_tax_burden);
    });

    it('uses the personal tax canton rather than the business canton', function () {
        $entity = BusinessEntity::factory()->forCanton('GE')->for($this->owner, 'owner')->create();
        ($this->revenue)($entity, 80000);

        $row = app(TaxEngine::class)->estimateFor($entity, 2026);

        expect($row->inputs['canton_code'])->toBe('ZH')
            ->and($row->canton_id)->toBe($this->owner->taxProfile->canton_id);
    });

    it('refreshes the sibling workspaces\' shares when one business changes', function () {
        $first = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['created_at' => now()->subDay()]);
        $second = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
        ($this->revenue)($first, 50000);
        ($this->revenue)($second, 50000);

        app(TaxEngine::class)->estimateAllFor($this->owner, 2026);
        expect($second->latestTaxEstimation(2026)->inputs['share'])->toBe('0.500000');

        $this->travel(1)->minutes();
        ($this->revenue)($first, 50000);

        (new RecalculateTaxEstimation($first->getKey(), 2026))->handle(app(TaxEngine::class));

        $secondRow = $second->latestTaxEstimation(2026);
        $personal = $this->owner->personalTaxEstimation(2026);

        expect($first->latestTaxEstimation(2026)->inputs['share'])->toBe('0.666667')
            ->and($secondRow->inputs['share'])->toBe('0.333333')
            ->and($secondRow->inputs['personal_estimation_id'])->toBe($personal->getKey())
            ->and((float) $personal->gross_revenue)->toBe(150000.0)
            ->and(TaxEstimation::where('business_entity_id', $second->getKey())->count())->toBe(2);
    });

    it('returns the fresh row of the requested business and keeps its alert level in sync', function () {
        $entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
        ($this->revenue)($entity, 95000);

        $row = app(TaxEngine::class)->estimateFor($entity, 2026);

        expect($row->business_entity_id)->toBe($entity->getKey())
            ->and($entity->vat_alert_level)->toBe($row->vat_alert_level);
    });

    it('splits three equal businesses into shares that add up to exactly 100 %', function () {
        $businesses = collect(range(1, 3))->map(function (int $day): BusinessEntity {
            $entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['created_at' => now()->subDays($day)]);
            ($this->revenue)($entity, 10000);

            return $entity;
        });

        app(TaxEngine::class)->estimateAllFor($this->owner, 2026);

        $rows = $businesses->map(fn (BusinessEntity $entity): TaxEstimation => $entity->latestTaxEstimation(2026));
        $shareSum = $rows->reduce(fn (string $sum, TaxEstimation $row): string => bcadd($sum, $row->inputs['share'], 6), '0');
        $percentSum = $rows->reduce(fn (string $sum, TaxEstimation $row): string => bcadd($sum, (string) $row->inputs['share_percent'], 1), '0');

        expect($shareSum)->toBe('1.000000')
            ->and($percentSum)->toBe('100.0')
            ->and($rows->map(fn (TaxEstimation $row): float => (float) $row->inputs['share_percent'])->sort()->values()->all())
            ->toBe([33.3, 33.3, 33.4]);
    });

    it('recalculates the personal estimate and every workspace from the queued job', function () {
        $entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
        ($this->revenue)($entity, 30000);

        (new RecalculatePersonalTaxEstimation($this->owner->getKey(), 2026))->handle(app(TaxEngine::class));

        expect($this->owner->personalTaxEstimation(2026))->not->toBeNull()
            ->and($entity->latestTaxEstimation(2026))->not->toBeNull();
    });
});

it('allocates rounding remainders to the largest business', function (array $netIncomes, array $fractions, array $percentages) {
    expect(IncomeShares::fractions($netIncomes))->toBe($fractions)
        ->and(IncomeShares::percentages($netIncomes))->toBe($percentages);
})->with([
    'three equal' => [['a' => 100, 'b' => 100, 'c' => 100], ['a' => '0.333334', 'b' => '0.333333', 'c' => '0.333333'], ['a' => 33.4, 'b' => 33.3, 'c' => 33.3]],
    'uneven' => [['a' => 1, 'b' => 1, 'c' => 5], ['a' => '0.142857', 'b' => '0.142857', 'c' => '0.714286'], ['a' => 14.3, 'b' => 14.3, 'c' => 71.4]],
    'with a loss' => [['a' => 500, 'b' => -200], ['a' => '1.000000', 'b' => '0'], ['a' => 100.0, 'b' => 0.0]],
    'no income' => [['a' => 0, 'b' => -5], ['a' => '0', 'b' => '0'], ['a' => 0.0, 'b' => 0.0]],
]);

describe('the VAT threshold is per person (H5)', function () {
    beforeEach(function () {
        $this->owner = User::factory()->owner()->create(['created_at' => '2026-01-01']);
        TaxProfile::factory()->forCanton('ZH')->for($this->owner)->create(['birth_year' => 1990]);

        $this->invoice = function (BusinessEntity $entity, float $amount): Invoice {
            return Invoice::factory()->for($entity, 'businessEntity')->create([
                'status' => InvoiceStatus::Sent,
                'subtotal' => $amount,
                'vat_amount' => 0,
                'total' => $amount,
                'issue_date' => '2026-03-01',
            ]);
        };
    });

    it('evaluates the threshold once on the owner\'s consolidated revenue', function () {
        $first = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['created_at' => '2026-01-01']);
        $second = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['created_at' => '2026-01-01']);
        ($this->invoice)($first, 40000);
        ($this->invoice)($second, 40000);

        $personal = app(TaxEngine::class)->estimateAllFor($this->owner, 2026);

        // 80,000 together is 80 % of the threshold — a warning — even though
        // neither business would reach 60 % on its own.
        expect((float) $personal->vat_threshold_pct)->toBe(80.0)
            ->and($personal->vat_alert_level)->toBe('warning')
            ->and($personal->inputs['vat']['scope'])->toBe('owner')
            ->and($personal->inputs['vat']['threshold'])->toBe(100000);

        foreach ([$first, $second] as $entity) {
            $row = $entity->latestTaxEstimation(2026);

            expect($row->vat_alert_level)->toBe('warning')
                ->and((float) $row->vat_threshold_pct)->toBe(80.0)
                ->and($row->inputs['vat']['scope'])->toBe('owner')
                ->and((float) $row->inputs['vat']['own_pct'])->toBe(40.0)
                ->and((float) $row->inputs['vat']['own_revenue'])->toBe(40000.0)
                ->and($entity->refresh()->vat_alert_level)->toBe('warning');
        }
    });

    it('makes registration mandatory on a single invoice at the threshold in any of the businesses', function () {
        $small = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['created_at' => '2026-01-01']);
        $big = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['created_at' => '2026-01-01']);
        ($this->invoice)($small, 1000);
        ($this->invoice)($big, 100000);

        app(TaxEngine::class)->estimateAllFor($this->owner, 2026);

        expect($small->latestTaxEstimation(2026)->vat_alert_level)->toBe('mandatory')
            ->and($small->latestTaxEstimation(2026)->inputs['vat']['single_invoice'])->toBeTrue()
            ->and($big->latestTaxEstimation(2026)->vat_alert_level)->toBe('mandatory');
    });

    it('keeps evaluating a company on its own figures', function () {
        $company = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create([
            'type' => BusinessEntityType::GmbH,
            'created_at' => '2026-01-01',
        ]);
        ($this->invoice)($company, 70000);

        $row = app(TaxEngine::class)->estimateFor($company, 2026);

        expect($row->inputs['vat']['scope'])->toBe('entity')
            ->and((float) $row->vat_threshold_pct)->toBe(70.0)
            ->and($row->vat_alert_level)->toBe('info');
    });

    it('escalates the owner notification with the wording of the band it reached', function () {
        $entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create(['created_at' => '2026-01-01']);
        ($this->invoice)($entity, 105000);

        app(TaxEngine::class)->estimateAllFor($this->owner, 2026);

        $notification = $this->owner->notifications()->latest()->first();
        $body = $notification->data['body'] ?? '';

        expect($entity->refresh()->vat_alert_level)->toBe('mandatory')
            ->and($notification->data['title'] ?? '')->toContain('mandatory')
            ->and($body)->toContain("CHF 100'000")
            ->and($body)->toContain('was mandatory within 30 days');
    });
});

describe('annualisation (step 10)', function () {
    it('counts the days from a mid-year start rather than from 1 January', function () {
        Carbon::setTestNow('2026-07-01');

        $owner = User::factory()->owner()->create(['created_at' => '2026-06-01']);
        TaxProfile::factory()->forCanton('ZH')->for($owner)->create(['birth_year' => 1990]);
        $entity = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create(['created_at' => '2026-06-01']);

        Invoice::factory()->for($entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent,
            'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
            'issue_date' => '2026-06-15',
        ]);

        $personal = app(TaxEngine::class)->estimateAllFor($owner, 2026);

        // 1 June to 1 July inclusive is 31 days, not the 182 since 1 January.
        expect($personal->inputs['days_elapsed'])->toBe(31)
            ->and((float) $personal->projected_annual_revenue)->toBe(round(10000 * 365 / 31, 2))
            ->and((float) $personal->projected_annual_revenue)->toBeGreaterThan(100000.0);

        Carbon::setTestNow();
    });

    it('does not treat a backdated import as a single day of trading', function () {
        Carbon::setTestNow('2026-07-01');

        // The account was opened today, but the invoices go back to January.
        $owner = User::factory()->owner()->create(['created_at' => '2026-07-01']);
        TaxProfile::factory()->forCanton('ZH')->for($owner)->create(['birth_year' => 1990]);
        $entity = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create(['created_at' => '2026-07-01']);

        Invoice::factory()->for($entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent,
            'subtotal' => 50000, 'vat_amount' => 0, 'total' => 50000,
            'issue_date' => '2026-01-10',
        ]);

        $personal = app(TaxEngine::class)->estimateAllFor($owner, 2026);

        expect($personal->inputs['days_elapsed'])->toBe(173)
            ->and((float) $personal->projected_annual_revenue)->toBe(round(50000 * 365 / 173, 2));

        Carbon::setTestNow();
    });

    it('stores a monthly set-aside taken from the projected year, not from the year to date', function () {
        Carbon::setTestNow('2026-07-01');

        $owner = User::factory()->owner()->create(['created_at' => '2026-01-01']);
        TaxProfile::factory()->forCanton('ZH')->for($owner)->create(['birth_year' => 1990]);
        $entity = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create(['created_at' => '2026-01-01']);

        Invoice::factory()->for($entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent,
            'subtotal' => 60000, 'vat_amount' => 0, 'total' => 60000,
            'issue_date' => '2026-02-01',
        ]);

        $personal = app(TaxEngine::class)->estimateAllFor($owner, 2026);
        $row = $entity->latestTaxEstimation(2026);

        expect((float) $personal->projected_total_tax)->toBeGreaterThan((float) $personal->total_tax_burden)
            ->and((float) $personal->projected_monthly_reserve)->toBe(round((float) $personal->projected_total_tax / 12, 2))
            ->and((float) $personal->monthly_reserve)->toBe(round((float) $personal->total_tax_burden / 12, 2))
            ->and((float) $row->projected_monthly_reserve)->toBe((float) $personal->projected_monthly_reserve);

        Carbon::setTestNow();
    });
});

it('charges the minimum AHV contribution to an owner with no revenue at all', function () {
    $owner = User::factory()->owner()->create(['created_at' => '2026-01-01']);
    TaxProfile::factory()->forCanton('ZH')->for($owner)->create(['birth_year' => 1990]);
    BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create(['created_at' => '2026-01-01']);

    $personal = app(TaxEngine::class)->estimateAllFor($owner, 2026);

    expect((float) $personal->gross_revenue)->toBe(0.0)
        ->and((float) $personal->total_social_insurance)->toBe(514.0)
        ->and((float) $personal->total_income_tax)->toBe(0.0)
        ->and((float) $personal->total_tax_burden)->toBe(514.0)
        ->and($personal->rates_snapshot['deductions']['minimum_contribution_applied'])->toBeTrue();
});

it('surfaces the loss-year and age-exemption flags on the stored snapshot', function () {
    $owner = User::factory()->owner()->create(['created_at' => '2026-01-01']);
    TaxProfile::factory()->forCanton('ZH')->for($owner)->create(['birth_year' => 1950]);
    $entity = BusinessEntity::factory()->forCanton('ZH')->for($owner, 'owner')->create(['created_at' => '2026-01-01']);

    Invoice::factory()->for($entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'issue_date' => '2026-02-01',
    ]);
    Expense::factory()->for($entity, 'businessEntity')->create([
        'status' => ExpenseStatus::Reviewed,
        'expense_date' => '2026-02-15',
        'deductible_amount' => 9000,
    ]);

    $personal = app(TaxEngine::class)->estimateAllFor($owner, 2026);
    $deductions = $personal->rates_snapshot['deductions'];

    // Past 65 the first CHF 16,800 is exempt, so a loss year leaves nothing
    // contributable and even the minimum does not apply.
    expect((float) $personal->net_income)->toBe(-4000.0)
        ->and($deductions['loss_year'])->toBeTrue()
        ->and($deductions['age_exemption_applied'])->toBeTrue()
        ->and($deductions['minimum_contribution_applied'])->toBeFalse()
        ->and((float) $personal->total_social_insurance)->toBe(0.0);
});
