<?php

use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\VatStatus;
use App\Filament\Workspace\Resources\Expenses\ExpenseResource;
use App\Filament\Workspace\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Workspace\Resources\Expenses\Pages\EditExpense;
use App\Filament\Workspace\Resources\Expenses\Pages\ListExpenses;
use App\Jobs\ProcessReceiptUpload;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);

    $this->owner = User::factory()->owner()->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();
    Subscription::factory()->forEntity($this->entity)->create(); // trialing → can write

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('workspace'));
    Filament::setTenant($this->entity);
});

it('creates a manual expense bound to the tenant', function () {
    Livewire::test(CreateExpense::class)
        ->fillForm([
            'vendor' => 'Local shop',
            'expense_date' => '2026-04-01',
            'amount' => 120,
            'vat_rate' => 8.1,
            'category_id' => ExpenseCategory::query()->value('id'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::first();

    expect($expense->business_entity_id)->toBe($this->entity->getKey())
        ->and($expense->processing_status)->toBe(ExpenseProcessingStatus::Manual)
        ->and($expense->status)->toBe(ExpenseStatus::PendingReview)
        ->and((float) $expense->vat_amount)->toBe(8.99) // VAT contained in the gross amount
        ->and((float) $expense->net_amount)->toBe(111.01);
});

it('queues extraction when a receipt is uploaded', function () {
    Storage::fake('receipts');
    Queue::fake();

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'expense_date' => '2026-04-01',
            'amount' => 0,
            'receipt_path' => UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::first();

    expect($expense->processing_status)->toBe(ExpenseProcessingStatus::Pending)
        ->and($expense->receipt_path)->not->toBeNull();

    Queue::assertPushed(ProcessReceiptUpload::class);
});

it('confirms an expense through the table action', function () {
    $expense = Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')
        ->create(['amount' => 200, 'deductible_pct' => 50]);

    Livewire::test(ListExpenses::class)
        ->callAction(TestAction::make('confirm')->table($expense));

    $expense->refresh();

    expect($expense->status)->toBe(ExpenseStatus::Reviewed)
        ->and((float) $expense->deductible_amount)->toBe(100.00);
});

it('serves a receipt to the owner but forbids others', function () {
    Storage::fake('receipts');
    Storage::disk('receipts')->put('receipts/r.pdf', 'x');
    $expense = Expense::factory()->for($this->entity, 'businessEntity')->create(['receipt_path' => 'receipts/r.pdf']);

    $this->get(route('receipts.show', $expense))->assertOk();

    $intruder = User::factory()->owner()->create();
    $this->actingAs($intruder)->get(route('receipts.show', $expense))->assertForbidden();
});

it('lists only the tenant\'s expenses', function () {
    $mine = Expense::factory()->for($this->entity, 'businessEntity')->create(['vendor' => 'Mine']);
    $theirs = Expense::factory()->create(['vendor' => 'Theirs']);

    Livewire::test(ListExpenses::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

/**
 * @return array<string, mixed>
 */
function manualExpenseData(array $overrides = []): array
{
    return [
        'vendor' => 'Papeterie Zürich',
        'expense_date' => '2026-04-01',
        'amount' => '108.10',
        'vat_rate' => '8.1',
        'vat_amount' => '8.10',
        'category_id' => ExpenseCategory::query()->value('id'),
        ...$overrides,
    ];
}

it('validates a manual expense', function (array $overrides, string $field, string $rule) {
    Livewire::test(CreateExpense::class)
        ->fillForm(manualExpenseData($overrides))
        ->call('create')
        ->assertHasFormErrors([$field => $rule]);

    expect(Expense::count())->toBe(0);
})->with([
    'zero amount' => [['amount' => 0], 'amount', 'gt'],
    'negative amount' => [['amount' => -50], 'amount', 'gt'],
    'missing amount' => [['amount' => null], 'amount', 'required'],
    'missing vendor' => [['vendor' => null], 'vendor', 'required'],
    'missing category' => [['category_id' => null], 'category_id', 'required'],
    'VAT rate above 100 %' => [['vat_rate' => 150], 'vat_rate', 'max'],
    'VAT above the amount' => [['vat_amount' => 200], 'vat_amount', 'lte'],
]);

it('lets a receipt upload leave amount, vendor and category empty', function () {
    Storage::fake('receipts');
    Queue::fake();

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'expense_date' => '2026-04-01',
            'amount' => null,
            'vendor' => null,
            'category_id' => null,
            'vat_amount' => null,
            'receipt_path' => UploadedFile::fake()->image('r.jpg'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::firstOrFail();

    expect((float) $expense->amount)->toBe(0.0)
        ->and((float) $expense->vat_amount)->toBe(0.0)
        ->and($expense->processing_status)->toBe(ExpenseProcessingStatus::Pending);
});

it('calculates the VAT amount from the gross amount and rate', function () {
    Livewire::test(CreateExpense::class)
        ->fillForm(['vat_amount' => null])
        ->set('data.amount', '108.10')
        ->set('data.vat_rate', '8.1')
        ->assertSchemaStateSet(['vat_amount' => '8.10'])
        ->set('data.vat_rate', '0')
        ->assertSchemaStateSet(['vat_amount' => '0.00']);
});

it('defaults the VAT rate to 0 % for a business that is not VAT-registered', function () {
    Livewire::test(CreateExpense::class)
        ->assertSchemaStateSet(['vat_rate' => '0']);
});

it('defaults the VAT rate to 8.1 % for a VAT-registered business', function () {
    $this->entity->forceFill(['vat_status' => VatStatus::RegisteredVoluntary, 'mwst_number' => 'CHE-148.830.302 MWST'])->save();

    Livewire::test(CreateExpense::class)
        ->assertSchemaStateSet(['vat_rate' => '8.1']);
});

it('derives a blank VAT amount on the server', function () {
    Livewire::test(CreateExpense::class)
        ->fillForm(manualExpenseData(['vat_amount' => null]))
        ->call('create')
        ->assertHasNoFormErrors();

    expect((string) Expense::firstOrFail()->vat_amount)->toBe('8.10');
});

it('rejects invalid values on edit and leaves the expense unchanged (BUG-21)', function (array $overrides, string $field, string $rule) {
    $expense = Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')->create([
        'vendor' => 'Original',
        'amount' => 100,
        'vat_rate' => 8.1,
        'vat_amount' => 7.49,
        'category_id' => ExpenseCategory::query()->value('id'),
    ]);

    Livewire::test(EditExpense::class, ['record' => $expense->getKey()])
        ->fillForm($overrides)
        ->call('save')
        ->assertHasFormErrors([$field => $rule]);

    $expense->refresh();
    expect((float) $expense->amount)->toBe(100.0)
        ->and((float) $expense->vat_rate)->toBe(8.1);
})->with([
    'negative amount' => [['amount' => -50], 'amount', 'gt'],
    'VAT rate 150 %' => [['vat_rate' => 150], 'vat_rate', 'max'],
]);

it('shows an inline confirm button for pending expenses only', function () {
    $pending = Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')->create();
    $confirmed = Expense::factory()->for($this->entity, 'businessEntity')->create(['status' => ExpenseStatus::Reviewed]);

    Livewire::test(ListExpenses::class)
        ->assertActionVisible(TestAction::make('confirm')->table($pending))
        ->assertActionHidden(TestAction::make('confirm')->table($confirmed))
        ->assertSee('Awaiting confirmation')
        ->assertSee('Only confirmed expenses count towards your tax estimate and VAT summary.');
});

it('refuses to confirm an expense without a category', function () {
    $expense = Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')
        ->create(['amount' => 200, 'category_id' => null]);

    Livewire::test(ListExpenses::class)
        ->callAction(TestAction::make('confirm')->table($expense))
        ->assertNotified('Add an amount and a category before confirming.');

    expect($expense->refresh()->status)->toBe(ExpenseStatus::PendingReview);
});

it('bulk-confirms valid pending expenses and skips incomplete ones', function () {
    $category = ExpenseCategory::query()->value('id');
    $valid = Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')
        ->count(2)->create(['amount' => 100, 'category_id' => $category]);
    $noAmount = Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')
        ->create(['amount' => 0, 'category_id' => $category]);

    Livewire::test(ListExpenses::class)
        ->selectTableRecords([...$valid->all(), $noAmount])
        ->callAction(TestAction::make('confirmSelected')->table()->bulk())
        ->assertNotified('2 expenses confirmed, 1 skipped (missing amount or category)');

    expect($valid->every(fn (Expense $expense): bool => $expense->refresh()->status === ExpenseStatus::Reviewed))->toBeTrue()
        ->and($noAmount->refresh()->status)->toBe(ExpenseStatus::PendingReview);
});

it('does not let a read-only (expired) workspace confirm expenses', function () {
    $this->entity->subscription->forceFill(['status' => SubscriptionStatus::Expired, 'trial_ends_at' => now()->subDay()])->save();
    $expense = Expense::factory()->pendingReview()->for($this->entity->refresh(), 'businessEntity')
        ->create(['amount' => 100, 'category_id' => ExpenseCategory::query()->value('id')]);

    expect($this->entity->canWrite())->toBeFalse();

    Livewire::test(ListExpenses::class)
        ->assertActionHidden(TestAction::make('confirm')->table($expense))
        ->selectTableRecords([$expense])
        ->assertActionHidden(TestAction::make('confirmSelected')->table()->bulk());

    expect($expense->refresh()->status)->toBe(ExpenseStatus::PendingReview);
});

it('shows the number of expenses awaiting confirmation in the navigation', function () {
    expect(ExpenseResource::getNavigationBadge())->toBeNull();

    Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')->count(3)->create();
    Expense::factory()->for($this->entity, 'businessEntity')->create(['status' => ExpenseStatus::Reviewed]);
    Expense::factory()->pendingReview()->create();

    expect(ExpenseResource::getNavigationBadge())->toBe('3')
        ->and(ExpenseResource::getNavigationBadgeColor())->toBe('warning');
});

it('creates and confirms a manual expense in one step', function () {
    Livewire::test(CreateExpense::class)
        ->fillForm(manualExpenseData())
        ->call('createAndConfirm')
        ->assertHasNoFormErrors();

    expect(Expense::firstOrFail()->status)->toBe(ExpenseStatus::Reviewed);
});

it('saves and confirms a pending expense from the edit page', function () {
    $expense = Expense::factory()->pendingReview()->for($this->entity, 'businessEntity')->create([
        'amount' => 0,
        'category_id' => null,
    ]);

    Livewire::test(EditExpense::class, ['record' => $expense->getKey()])
        ->assertActionVisible(TestAction::make('saveAndConfirm')->schemaComponent('form-actions', 'content'))
        ->fillForm(manualExpenseData())
        ->call('saveAndConfirm')
        ->assertHasNoFormErrors()
        ->assertRedirect(ExpenseResource::getUrl('index'));

    $expense->refresh();
    expect($expense->status)->toBe(ExpenseStatus::Reviewed)
        ->and((string) $expense->amount)->toBe('108.10');
});
