<?php

use App\Enums\DeductibilityStatus;
use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Events\ExpenseProcessingUpdated;
use App\Jobs\ProcessReceiptUpload;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\User;
use App\Services\Expenses\ExpenseService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(ReferenceDataSeeder::class);
});

it('extracts receipt data, matches a category and marks the expense extracted', function () {
    Event::fake([ExpenseProcessingUpdated::class]);
    Storage::fake('receipts');

    $entity = BusinessEntity::factory()->create();
    Storage::disk('receipts')->put('receipts/r.jpg', 'bytes');
    $expense = Expense::factory()->pendingReview()->for($entity, 'businessEntity')->create([
        'processing_status' => ExpenseProcessingStatus::Pending,
        'receipt_path' => 'receipts/r.jpg',
        'amount' => 0,
        'vendor' => null,
    ]);

    (new ProcessReceiptUpload($expense->getKey()))->handle(app(ExpenseService::class));
    $expense->refresh();

    // FakeExtractor fixture: SBB, CHF 87.50, VAT 6.63 @ 8.1%, hint "travel".
    expect($expense->processing_status)->toBe(ExpenseProcessingStatus::Extracted)
        ->and($expense->vendor)->toBe('SBB CFF FFS')
        ->and((float) $expense->amount)->toBe(87.50)
        ->and((float) $expense->vat_amount)->toBe(6.63)
        ->and((float) $expense->net_amount)->toBe(80.87)
        ->and($expense->category?->code)->toBe('cat_travel')
        ->and($expense->status)->toBe(ExpenseStatus::PendingReview); // still awaits human confirmation

    Event::assertDispatched(ExpenseProcessingUpdated::class);
});

it('confirms an expense, recomputing the deductible amount and refreshing tax', function () {
    Queue::fake();
    $entity = BusinessEntity::factory()->create();
    $expense = Expense::factory()->pendingReview()->for($entity, 'businessEntity')->create([
        'amount' => 200,
        'deductibility' => DeductibilityStatus::PartiallyDeductible,
        'deductible_pct' => 50,
    ]);

    app(ExpenseService::class)->confirm($expense);
    $expense->refresh();

    expect($expense->status)->toBe(ExpenseStatus::Reviewed)
        ->and((float) $expense->deductible_amount)->toBe(100.00);

    Queue::assertPushed(RecalculateTaxEstimation::class);
});

it('records a failure and notifies the owner', function () {
    $owner = User::factory()->owner()->create();
    $entity = BusinessEntity::factory()->for($owner, 'owner')->create();
    $expense = Expense::factory()->pendingReview()->for($entity, 'businessEntity')->create([
        'processing_status' => ExpenseProcessingStatus::Processing,
    ]);

    app(ExpenseService::class)->markFailed($expense, 'Extraction provider error (HTTP 500).');
    $expense->refresh();

    expect($expense->processing_status)->toBe(ExpenseProcessingStatus::Failed)
        ->and($expense->processing_error)->toContain('HTTP 500')
        ->and($owner->notifications()->count())->toBe(1);
});

it('matches category hints to seeded categories', function () {
    $service = app(ExpenseService::class);

    expect($service->matchCategory('meals')?->code)->toBe('cat_meals')
        ->and($service->matchCategory('software')?->code)->toBe('cat_software')
        ->and($service->matchCategory('total-nonsense-xyz'))->toBeNull();
});
