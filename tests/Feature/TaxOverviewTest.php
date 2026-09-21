<?php

use App\Enums\ExpenseStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Workspace\Pages\TaxOverview;
use App\Filament\Workspace\Widgets\BusinessOverview;
use App\Filament\Workspace\Widgets\TaxBreakdownWidget;
use App\Filament\Workspace\Widgets\ToDoWidget;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Tax\TaxEngine;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

function activeSubscription(User $owner, string $code): void
{
    $plan = Plan::where('code', $code)->firstOrFail();
    $entity = $owner->ownedEntities()->oldest()->firstOrFail();
    Subscription::factory()->forEntity($entity)->create([
        'plan_id' => $plan->getKey(),
        'status' => SubscriptionStatus::Active,
    ]);
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('workspace'));
    Filament::setTenant($this->entity);
});

it('renders the business overview widget', function () {
    activeSubscription($this->owner, 'pro');

    Livewire::test(BusinessOverview::class)
        ->assertOk()
        ->assertSee('Revenue YTD');
});

it('renders the tax page with the latest estimation for a pro user', function () {
    activeSubscription($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'subtotal' => 50000,
        'vat_amount' => 0,
        'total' => 50000,
        'issue_date' => now(),
    ]);
    app(TaxEngine::class)->estimateFor($this->entity);

    Livewire::test(TaxOverview::class)
        ->assertOk()
        ->assertSee('Tax owed so far')
        ->assertSee('Expected for the full year')
        ->assertSee('Set aside monthly')
        ->assertSee('This business is 100')
        ->assertSee('See your full personal tax')
        ->assertDontSee('Canton comparison');
});

it('gates the tax page on the Solo plan when feature gates are enforced', function () {
    config(['settlo.enforce_feature_gates' => true]);

    activeSubscription($this->owner, 'solo');

    $this->get(TaxOverview::getUrl(tenant: $this->entity))->assertForbidden();
});

it('leaves the tax page open on the Solo plan while gates are off (POC default)', function () {
    expect(config('settlo.enforce_feature_gates'))->toBeFalse();

    activeSubscription($this->owner, 'solo');

    $this->get(TaxOverview::getUrl(tenant: $this->entity))->assertSuccessful();
});

it('allows the tax page on a plan that includes the tax engine', function () {
    activeSubscription($this->owner, 'pro');

    $this->get(TaxOverview::getUrl(tenant: $this->entity))->assertSuccessful();
});

it('notes expenses awaiting confirmation on the tax estimate and the dashboard widget', function () {
    activeSubscription($this->owner, 'pro');
    $year = (int) config('settlo.current_fiscal_year', now()->year);

    Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')->create([
        'expense_date' => "{$year}-05-01",
        'amount' => 80,
    ]);

    Livewire::test(TaxOverview::class)
        ->assertSee('1 expense totalling CHF 80.00 is')
        ->assertSee('Review expenses');

    Livewire::test(TaxBreakdownWidget::class)
        ->assertSee('1 expense totalling CHF 80.00 awaiting confirmation is');
});

it('shows the AHV parts, the minimum contribution badge and the deduction lines', function () {
    activeSubscription($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'subtotal' => 966.41,
        'vat_amount' => 0,
        'total' => 966.41,
        'issue_date' => now(),
    ]);
    app(TaxEngine::class)->estimateFor($this->entity);

    Livewire::test(TaxOverview::class)
        ->assertOk()
        ->assertSee('Total AHV / IV / EO')
        ->assertSee('Minimum contribution')
        ->assertSee('Self-employed people pay at least CHF 514 per year')
        ->assertSee('AHV deduction (50 % of AHV)')
        ->assertSee('Taxable income')
        ->assertSee('Revenue (excl. VAT)')
        ->assertDontSee('Gross revenue');
});

it('shows a breakdown that adds up for an owner with two businesses', function () {
    activeSubscription($this->owner, 'pro');
    TaxProfile::factory()->forCanton('ZH')->for($this->owner)->create([
        'pillar3a_amount' => 7056,
        'number_of_children' => 2,
        'other_income' => 5000,
    ]);
    $second = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    foreach ([[$this->entity, 60000], [$second, 40000]] as [$entity, $amount]) {
        Invoice::factory()->for($entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent,
            'subtotal' => $amount,
            'vat_amount' => 0,
            'total' => $amount,
            'issue_date' => now(),
        ]);
    }

    $personal = app(TaxEngine::class)->estimateAllFor($this->owner);
    $row = $this->entity->latestTaxEstimation((int) config('settlo.current_fiscal_year', now()->year));
    $deductions = array_map(fn (mixed $value): mixed => is_int($value) ? (float) $value : $value, $row->rates_snapshot['deductions']);
    $personalDeductions = array_map(fn (mixed $value): mixed => is_int($value) ? (float) $value : $value, $personal->rates_snapshot['deductions']);

    expect($deductions['share'])->toBe('0.600000')
        ->and($deductions['net_income'])->toBe(60000.0)
        ->and($deductions['ahv'])->toBe(round($personalDeductions['ahv'] * 0.6, 2))
        ->and($deductions['pillar3a'])->toBe(round($personalDeductions['pillar3a'] * 0.6, 2))
        ->and($deductions['children'])->toBe(round($personalDeductions['children'] * 0.6, 2))
        ->and($deductions['other_income'])->toBe(3000.0)
        ->and((float) $row->taxable_income)->toBe(round(
            $deductions['net_income'] - $deductions['ahv'] - $deductions['pillar3a'] - $deductions['children'] + $deductions['other_income'],
            2,
        ))
        ->and((float) $row->taxable_income)->toEqualWithDelta((float) $personal->taxable_income * 0.6, 0.05);

    Livewire::test(TaxOverview::class)
        ->assertOk()
        ->assertSee('Share of your net income')
        ->assertSee("CHF 60'000.00")
        ->assertSee('CHF '.number_format($deductions['pillar3a'], 2, '.', "'"))
        ->assertSee('CHF '.number_format((float) $row->taxable_income, 2, '.', "'"));
});

it('shows the owner-level VAT threshold alongside this workspace\'s contribution', function () {
    activeSubscription($this->owner, 'pro');
    $second = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    foreach ([[$this->entity, 50000], [$second, 45000]] as [$entity, $amount]) {
        Invoice::factory()->for($entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent,
            'subtotal' => $amount,
            'vat_amount' => 0,
            'total' => $amount,
            'issue_date' => now(),
        ]);
    }

    app(TaxEngine::class)->estimateAllFor($this->owner);

    // 95,000 together is the critical band, although this workspace only
    // brought in 50,000 of it.
    Livewire::test(TaxOverview::class)
        ->assertOk()
        ->assertSee('all of your sole proprietorships together')
        ->assertSee('this one contributes 50.0%')
        ->assertSee('Register for VAT now');
});

it('escalates the VAT wording on the to-do list', function () {
    activeSubscription($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'subtotal' => 120000,
        'vat_amount' => 0,
        'total' => 120000,
        'issue_date' => now(),
    ]);

    app(TaxEngine::class)->estimateFor($this->entity);

    Livewire::test(ToDoWidget::class)
        ->assertOk()
        ->assertSee('registration was mandatory within 30 days');
});

it('shows the loss-year note on the breakdown', function () {
    activeSubscription($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'subtotal' => 5000,
        'vat_amount' => 0,
        'total' => 5000,
        'issue_date' => now(),
    ]);
    Expense::factory()->for($this->entity, 'businessEntity')->create([
        'status' => ExpenseStatus::Reviewed,
        'expense_date' => now(),
        'deductible_amount' => 12000,
    ]);

    app(TaxEngine::class)->estimateFor($this->entity);

    Livewire::test(TaxOverview::class)
        ->assertOk()
        ->assertSee('Loss year')
        ->assertSee('minimum AHV contribution of CHF 514 still applies');
});
