<?php

use App\Enums\DeductibilityStatus;
use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Events\ExpenseProcessingUpdated;
use App\Jobs\ProcessReceiptUpload;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\Expenses\ExpenseService;
use App\Services\Extraction\ExtractionResult;
use App\Services\Extraction\ReceiptExtractor;
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

it('files an unrecognised category under Uncategorised, records the reasoning and keeps the confidence honest', function () {
    Storage::fake('receipts');
    $this->mock(ReceiptExtractor::class)
        ->shouldReceive('extract')
        ->andReturn(new ExtractionResult(
            vendorName: 'Zeppelin Rental GmbH',
            documentDate: '2026-04-02',
            totalAmount: 120.00,
            currency: 'CHF',
            vatAmount: 9.00,
            vatRate: 8.1,
            categoryHint: 'airship mooring',
            confidence: 0.91,
        ));

    $entity = BusinessEntity::factory()->create();
    Storage::disk('receipts')->put('receipts/z.jpg', 'bytes');
    $expense = Expense::factory()->pendingReview()->for($entity, 'businessEntity')->create([
        'processing_status' => ExpenseProcessingStatus::Pending,
        'receipt_path' => 'receipts/z.jpg',
        'amount' => 0,
        'category_id' => null,
    ]);

    app(ExpenseService::class)->runExtraction($expense);
    $expense->refresh();

    expect($expense->category?->code)->toBe(ExpenseService::FALLBACK_CATEGORY_CODE)
        ->and($expense->category?->name_en)->toBe('Uncategorised')
        ->and($expense->deductibility)->toBe(DeductibilityStatus::Uncertain)
        ->and($expense->ai_reasoning)->toContain('airship mooring')
        ->and($expense->ai_reasoning)->toContain('Uncategorised')
        // We did not recognise the category, so the AI confidence is zero even
        // though the extraction itself was confident.
        ->and((float) $expense->ai_confidence)->toBe(0.0)
        ->and((float) $expense->ocr_confidence)->toBe(0.91)
        // It still has a category and an amount, so the user can confirm it.
        ->and(ExpenseService::canBeConfirmed($expense))->toBeTrue();
});

it('records the reasoning when the category is recognised', function () {
    Storage::fake('receipts');
    $entity = BusinessEntity::factory()->create();
    Storage::disk('receipts')->put('receipts/r.jpg', 'bytes');
    $expense = Expense::factory()->pendingReview()->for($entity, 'businessEntity')->create([
        'processing_status' => ExpenseProcessingStatus::Pending,
        'receipt_path' => 'receipts/r.jpg',
    ]);

    app(ExpenseService::class)->runExtraction($expense);

    expect($expense->refresh()->ai_reasoning)->toContain('Business travel')
        ->and($expense->category?->code)->toBe('cat_travel');
});

it('never suggests the fallback category as a match for a hint', function () {
    ExpenseService::fallbackCategory();

    expect(app(ExpenseService::class)->matchCategory('uncategorised'))->toBeNull();
});

describe('the deductible amount is exact (BCMath)', function () {
    it('does not lose a centime to binary floating point', function (string $amount, float $pct, string $expected) {
        $expense = Expense::factory()->pendingReview()->for(BusinessEntity::factory()->create(), 'businessEntity')->create([
            'amount' => $amount,
            'deductible_pct' => $pct,
            'category_id' => ExpenseCategory::where('code', 'cat_equipment')->value('id'),
        ]);

        app(ExpenseService::class)->confirm($expense);

        expect((string) $expense->refresh()->deductible_amount)->toBe($expected);
    })->with([
        // 1,073.85 x 90 % is exactly 966.465: binary floats round it down.
        'an exact half-centime at 90 %' => ['1073.85', 90.0, '966.47'],
        // 2,698.20 x 87.5 % is exactly 2,360.925.
        'an exact half-centime at 87.5 %' => ['2698.20', 87.5, '2360.93'],
        'a third of an odd amount' => ['1234.55', 33.0, '407.40'],
        'full deductibility is exact' => ['999.99', 100.0, '999.99'],
        'nothing is deductible' => ['500.00', 0.0, '0.00'],
    ]);

    it('recomputes the same way outside confirmation', function () {
        $expense = Expense::factory()->for(BusinessEntity::factory()->create(), 'businessEntity')->create([
            'status' => ExpenseStatus::PendingReview,
            'amount' => '1234.55',
            'deductible_pct' => 33,
        ]);

        app(ExpenseService::class)->recomputeDeductible($expense);

        expect((string) $expense->refresh()->deductible_amount)->toBe('407.40')
            ->and(ExpenseService::deductibleAmount('1234.55', '33'))->toBe('407.40');
    });

    it('falls back to the deductibility status default when no percentage is set', function () {
        $expense = Expense::factory()->for(BusinessEntity::factory()->create(), 'businessEntity')->create([
            'status' => ExpenseStatus::PendingReview,
            'amount' => '300.00',
            'deductible_pct' => null,
            'deductibility' => DeductibilityStatus::PartiallyDeductible,
        ]);

        app(ExpenseService::class)->recomputeDeductible($expense);

        expect((float) $expense->refresh()->deductible_pct)->toBe(50.0)
            ->and((string) $expense->deductible_amount)->toBe('150.00');
    });
});

it('defaults home office to a pro-rata share rather than 100 %', function () {
    $category = ExpenseCategory::where('code', 'cat_homeoffice')->firstOrFail();

    expect($category->default_deductibility)->toBe(DeductibilityStatus::PartiallyDeductible)
        ->and((float) $category->default_deductible_pct)->toBe(25.0)
        ->and($category->notes)->toContain('Pro-rata')
        ->and($category->requires_proof)->toBeTrue();
});
