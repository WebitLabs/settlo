<?php

use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Filament\App\Resources\Expenses\Pages\CreateExpense;
use App\Filament\App\Resources\Expenses\Pages\ListExpenses;
use App\Jobs\ProcessReceiptUpload;
use App\Models\BusinessEntity;
use App\Models\Expense;
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
    Subscription::factory()->for($this->owner, 'user')->create();
    $this->entity = BusinessEntity::factory()->forCanton('ZH')->for($this->owner, 'owner')->create();

    $this->actingAs($this->owner);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->entity);
});

it('creates a manual expense bound to the tenant', function () {
    Livewire::test(CreateExpense::class)
        ->fillForm([
            'vendor' => 'Local shop',
            'expense_date' => '2026-04-01',
            'amount' => 120,
            'vat_amount' => 9.72,
            'vat_rate' => 8.1,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $expense = Expense::first();

    expect($expense->business_entity_id)->toBe($this->entity->getKey())
        ->and($expense->processing_status)->toBe(ExpenseProcessingStatus::Manual)
        ->and($expense->status)->toBe(ExpenseStatus::PendingReview)
        ->and((float) $expense->net_amount)->toBe(110.28);
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
