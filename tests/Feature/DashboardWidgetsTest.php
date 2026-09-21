<?php

use App\Enums\AiEscalationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\VatStatus;
use App\Events\VatAlertRaised;
use App\Filament\Personal\Pages\Billing;
use App\Filament\Personal\Pages\PersonalTax;
use App\Filament\Workspace\Pages\BusinessSettings;
use App\Filament\Workspace\Pages\Dashboard;
use App\Filament\Workspace\Resources\Clients\ClientResource;
use App\Filament\Workspace\Resources\Invoices\InvoiceResource;
use App\Filament\Workspace\Widgets\BankAccountsWidget;
use App\Filament\Workspace\Widgets\BusinessOverview;
use App\Filament\Workspace\Widgets\CashOverview;
use App\Filament\Workspace\Widgets\ProfitChart;
use App\Filament\Workspace\Widgets\RecentExpenses;
use App\Filament\Workspace\Widgets\RecentInvoices;
use App\Filament\Workspace\Widgets\TaxBreakdownWidget;
use App\Filament\Workspace\Widgets\ToDoWidget;
use App\Filament\Workspace\Widgets\VatThresholdWidget;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BankAccount;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Reporting\BusinessMetrics;
use App\Services\Tax\TaxEngine;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Give the owner an active subscription on the given plan code.
 */
function subscribeOwner(User $owner, string $code): void
{
    $plan = Plan::where('code', $code)->firstOrFail();
    $entity = $owner->ownedEntities()->oldest()->firstOrFail();
    Subscription::factory()->forEntity($entity)->create([
        'plan_id' => $plan->getKey(),
        'status' => SubscriptionStatus::Active,
    ]);
}

/**
 * Create an answered-but-unresolved accountant escalation for the tenant.
 */
function answeredEscalation(BusinessEntity $entity, User $owner): AiEscalation
{
    $conversation = AiConversation::create([
        'user_id' => $owner->getKey(),
        'business_entity_id' => $entity->getKey(),
        'title' => 'VAT question',
    ]);

    $message = AiMessage::create([
        'conversation_id' => $conversation->getKey(),
        'role' => 'assistant',
        'content' => 'Answer body',
    ]);

    $escalation = AiEscalation::create([
        'conversation_id' => $conversation->getKey(),
        'message_id' => $message->getKey(),
        'user_id' => $owner->getKey(),
        'user_question' => 'Do I need to register for VAT?',
        'ai_answer' => 'It depends on your revenue.',
    ]);

    $escalation->forceFill([
        'status' => AiEscalationStatus::Answered,
        'answered_at' => now(),
        'resolved_at' => null,
    ])->save();

    return $escalation;
}

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('workspace'));
    Filament::setTenant($this->entity);
});

it('renders the dashboard widgets with no data', function () {
    subscribeOwner($this->owner, 'pro');

    Livewire::test(TaxBreakdownWidget::class)
        ->assertOk()
        ->assertSee('Complete your tax profile');

    Livewire::test(VatThresholdWidget::class)
        ->assertOk()
        ->assertSee('VAT threshold');

    Livewire::test(ToDoWidget::class)
        ->assertOk()
        ->assertSee('Complete your tax profile');
});

it('renders the tax breakdown widget with an estimation', function () {
    subscribeOwner($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'total' => 40000,
        'issue_date' => now(),
    ]);
    app(TaxEngine::class)->estimateFor($this->entity);

    Livewire::test(TaxBreakdownWidget::class)
        ->assertOk()
        ->assertSee('Tax owed so far')
        ->assertSee('Expected for the full year')
        ->assertSee('Set aside monthly');
});

it('shows a filled VAT progress bar once revenue exists', function () {
    subscribeOwner($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'total' => 50000,
        'issue_date' => now(),
    ]);
    app(TaxEngine::class)->estimateFor($this->entity);

    Livewire::test(VatThresholdWidget::class)
        ->assertOk()
        ->assertSee('%');
});

it('surfaces a draft invoice and an answered escalation in the to-do list', function () {
    subscribeOwner($this->owner, 'pro');

    $draft = Invoice::factory()->for($this->entity, 'businessEntity')->draft()->create();
    answeredEscalation($this->entity, $this->owner);

    Livewire::test(ToDoWidget::class)
        ->assertOk()
        ->assertSee("Review & send invoice {$draft->invoice_number}")
        ->assertSee(InvoiceResource::getUrl('view', ['record' => $draft], tenant: $this->entity), false)
        ->assertDontSee(InvoiceResource::getUrl('edit', ['record' => $draft], tenant: $this->entity), false)
        ->assertSee('Your accountant answered a question');
});

it('surfaces the VAT alert in the to-do list once the entity is flagged', function () {
    subscribeOwner($this->owner, 'pro');
    $this->entity->forceFill(['vat_alert_level' => 'warning'])->save();

    Livewire::test(ToDoWidget::class)
        ->assertOk()
        ->assertSee('Consider VAT registration');
});

it('fires the VAT notification once when crossing into the warning band', function () {
    subscribeOwner($this->owner, 'pro');
    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'subtotal' => 80000,
        'vat_amount' => 0,
        'total' => 80000,
        'issue_date' => now(),
    ]);

    app(TaxEngine::class)->estimateFor($this->entity);

    expect($this->entity->fresh()->vat_alert_level)->toBe('warning');
    expect($this->owner->fresh()->notifications()->count())->toBe(1);

    // Re-running with the same figures must not re-notify.
    app(TaxEngine::class)->estimateFor($this->entity->fresh());

    expect($this->owner->fresh()->notifications()->count())->toBe(1);
});

it('resets the stored level on downgrade and re-arms the notification', function () {
    subscribeOwner($this->owner, 'pro');
    $invoice = Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'subtotal' => 80000,
        'vat_amount' => 0,
        'total' => 80000,
        'issue_date' => now(),
    ]);

    app(TaxEngine::class)->estimateFor($this->entity);
    expect($this->owner->fresh()->notifications()->count())->toBe(1);

    // Revenue drops below the band — level resets, no new notification.
    $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();
    app(TaxEngine::class)->estimateFor($this->entity->fresh());
    expect($this->entity->fresh()->vat_alert_level)->not->toBe('warning');
    expect($this->owner->fresh()->notifications()->count())->toBe(1);

    // Revenue climbs back — the notification fires again.
    $invoice->forceFill(['status' => InvoiceStatus::Sent])->save();
    app(TaxEngine::class)->estimateFor($this->entity->fresh());
    expect($this->owner->fresh()->notifications()->count())->toBe(2);
});

it('broadcasts a VAT alert when the level rises', function () {
    subscribeOwner($this->owner, 'pro');
    Event::fake([VatAlertRaised::class]);

    Invoice::factory()->for($this->entity, 'businessEntity')->create([
        'status' => InvoiceStatus::Sent,
        'subtotal' => 80000,
        'vat_amount' => 0,
        'total' => 80000,
        'issue_date' => now(),
    ]);
    app(TaxEngine::class)->estimateFor($this->entity);

    Event::assertDispatched(VatAlertRaised::class, fn (VatAlertRaised $event): bool => $event->businessEntityId === $this->entity->getKey() && $event->level === 'warning');
});

it('renders the dashboard page with the greeting header', function () {
    subscribeOwner($this->owner, 'pro');

    Livewire::test(Dashboard::class)
        ->assertOk()
        ->assertSee($this->owner->first_name);
});

describe('workspace dashboard (D6)', function () {
    beforeEach(function () {
        config(['settlo.current_fiscal_year' => (int) now()->year]);
        subscribeOwner($this->owner, 'pro');

        $this->foreign = BusinessEntity::factory()->forCanton('ZH')->create();
        Invoice::factory()->for($this->foreign, 'businessEntity')->create([
            'invoice_number' => 'FOREIGN-1', 'status' => InvoiceStatus::Sent, 'subtotal' => 77777, 'vat_amount' => 0, 'total' => 77777, 'issue_date' => now(),
        ]);
        Expense::factory()->for($this->foreign, 'businessEntity')->create(['vendor' => 'Foreign Vendor', 'expense_date' => now()]);
        BankAccount::forceCreate(['business_entity_id' => $this->foreign->getKey(), 'account_name' => 'Foreign account', 'bank_name' => 'UBS', 'iban' => 'CH5604835012345678009']);
    });

    it('shows the business type and the quick actions', function () {
        Livewire::test(Dashboard::class)
            ->assertSee('Sole proprietorship')
            ->assertActionHasUrl('newClient', ClientResource::getUrl('create', tenant: $this->entity))
            ->assertActionExists('newInvoice')
            ->assertActionExists('uploadReceipt');
    });

    it('renders the new widgets without data', function () {
        // Without a tax profile the tax stat invites the owner to add one
        // instead of showing a figure.
        Livewire::test(BusinessOverview::class)->assertOk()->assertSee('Profit YTD')->assertSee('Add your tax profile to see this');
        Livewire::test(CashOverview::class)->assertOk()->assertSee('Cash received YTD')->assertSee('Nothing overdue')->assertDontSee('VAT collected YTD');
        Livewire::test(ProfitChart::class)->assertOk();
        Livewire::test(BankAccountsWidget::class)->assertOk()->assertSee('No bank accounts yet')->assertDontSee('Foreign account');
        Livewire::test(RecentExpenses::class)->assertOk()->assertSee('No expenses yet')->assertDontSee('Foreign Vendor');
        Livewire::test(RecentInvoices::class)->assertOk()->assertDontSee('FOREIGN-1');
    });

    it('shows the business numbers from BusinessMetrics', function () {
        $this->entity->forceFill(['vat_status' => VatStatus::RegisteredVoluntary, 'mwst_number' => 'CHE-123.456.789 MWST'])->save();
        $invoice = Invoice::factory()->for($this->entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent, 'subtotal' => 10000, 'vat_amount' => 810, 'total' => 10810,
            'issue_date' => now()->startOfYear()->addDays(10), 'due_date' => now()->subDay(),
        ]);
        InvoicePayment::create(['invoice_id' => $invoice->getKey(), 'amount' => 2500, 'paid_at' => now()->toDateString()]);
        Expense::factory()->for($this->entity, 'businessEntity')->create([
            'vendor' => 'Office Supplies AG', 'amount' => 1500, 'deductible_amount' => 1200, 'expense_date' => now()->startOfYear()->addDays(5),
        ]);
        app(TaxEngine::class)->estimateFor($this->entity);

        $metrics = app(BusinessMetrics::class)->forEntity($this->entity);
        expect($metrics->profit)->toBe('8800.00');

        Livewire::test(BusinessOverview::class)
            ->assertSee("CHF 10'000")
            // Expenses YTD shows the deductible amount, with the gross spend
            // as the sub-label and the net margin under the profit.
            ->assertSee("CHF 1'200")
            ->assertSee("Deductible of CHF 1'500 confirmed spend")
            ->assertSee("CHF 8'800")
            ->assertSee('88% net margin')
            ->assertSee('Estimated tax (this business)')
            // The headline is the tax owed so far; the sub-label is one twelfth
            // of the projected full year, not of the amount owed so far.
            ->assertSee('Set aside CHF')
            ->assertSee('/ month')
            ->assertDontSee("CHF 77'777");

        Livewire::test(CashOverview::class)
            ->assertSee("CHF 2'500")
            ->assertSee("CHF 10'810")
            ->assertSee('Follow up with your clients')
            ->assertSee('VAT collected YTD')
            ->assertSee('CHF 810');

        $month = now()->startOfYear()->addDays(10)->month - 1;
        $figures = Livewire::test(ProfitChart::class)->instance()->monthlyFigures();
        expect($figures['revenue'][$month])->toBe(10000.0)
            ->and($figures['expenses'][now()->startOfYear()->addDays(5)->month - 1])->toBe(1200.0)
            ->and(array_sum($figures['profit']))->toBe(8800.0);

        Livewire::test(RecentExpenses::class)
            ->assertSee('Office Supplies AG')
            ->assertDontSee('Foreign Vendor');

        Livewire::test(RecentInvoices::class)
            ->assertSee(InvoiceResource::getUrl('view', ['record' => $invoice], tenant: $this->entity), false);
    });

    it('lists the bank accounts with masked IBANs', function () {
        BankAccount::forceCreate([
            'business_entity_id' => $this->entity->getKey(),
            'account_name' => 'Main account',
            'bank_name' => 'PostFinance',
            'iban' => 'CH9300762011623852957',
            'is_default' => true,
        ]);

        Livewire::test(BankAccountsWidget::class)
            ->assertSee('Main account')
            ->assertSee('CH93 •••• •••• 5295 7')
            ->assertDontSee('CH9300762011623852957')
            ->assertDontSee('Foreign account');
    });

    it('masks IBANs', function (string $iban, string $masked) {
        expect(BankAccountsWidget::maskIban($iban))->toBe($masked);
    })->with([
        'swiss' => ['CH93 0076 2011 6238 5295 7', 'CH93 •••• •••• 5295 7'],
        'liechtenstein' => ['LI21088100002324013AA', 'LI21 •••• •••• 013A A'],
    ]);

    it('asks for an IBAN only when the business has none', function () {
        Livewire::test(ToDoWidget::class)->assertDontSee('Add your IBAN to send QR invoices');

        $this->entity->forceFill(['iban' => null])->save();

        Livewire::test(ToDoWidget::class)
            ->assertSee('Add your IBAN to send QR invoices')
            ->assertSee(BusinessSettings::getUrl(['tab' => BusinessSettings::INVOICING_TAB], tenant: $this->entity), false);
    });

    it('opens the invoicing tab from the IBAN to-do link', function () {
        $this->get(BusinessSettings::getUrl(['tab' => BusinessSettings::INVOICING_TAB], tenant: $this->entity))
            ->assertOk()
            ->assertSee('"'.BusinessSettings::INVOICING_TAB.'"', false);
    });

    it('reminds about the trial ending within five days', function (int $daysLeft, bool $visible) {
        $this->entity->subscription->forceFill([
            'status' => SubscriptionStatus::Trialing,
            'trial_ends_at' => now()->addDays($daysLeft)->subMinute(),
        ])->save();

        $component = Livewire::test(ToDoWidget::class);

        $visible
            ? $component->assertSee("Your trial ends in {$daysLeft} days — choose a plan")->assertSee(Billing::getUrl(['workspace' => $this->entity->getKey()], panel: 'app'), false)
            : $component->assertDontSee('Your trial ends');
    })->with([
        '5 days' => [5, true],
        '2 days' => [2, true],
        '6 days' => [6, false],
    ]);

    it('asks to update the payment method after a failed payment', function () {
        $this->entity->subscription->forceFill(['status' => SubscriptionStatus::PastDue])->save();

        Livewire::test(ToDoWidget::class)->assertSee('Payment failed — update your payment method');
    });

    it('links the tax widget to the personal tax page', function () {
        Invoice::factory()->for($this->entity, 'businessEntity')->create([
            'status' => InvoiceStatus::Sent, 'subtotal' => 30000, 'vat_amount' => 0, 'total' => 30000, 'issue_date' => now(),
        ]);
        app(TaxEngine::class)->estimateFor($this->entity);

        Livewire::test(TaxBreakdownWidget::class)
            ->assertSee('View your personal tax')
            ->assertSee(PersonalTax::getUrl(panel: 'app'), false);
    });

    it('renders the whole dashboard over HTTP', function () {
        $this->get(Dashboard::getUrl(tenant: $this->entity))->assertOk();
    });
});
