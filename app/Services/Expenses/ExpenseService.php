<?php

namespace App\Services\Expenses;

use App\Enums\DeductibilityStatus;
use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Events\ExpenseProcessingUpdated;
use App\Jobs\ProcessReceiptUpload;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Extraction\ReceiptExtractor;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Drives an uploaded receipt through the extraction pipeline and owns the
 * server-authoritative expense mutations. Processing state, OCR/AI metadata and
 * deductible_amount are always set here (guarded columns), never mass-assigned.
 */
class ExpenseService
{
    public function __construct(private readonly ReceiptExtractor $extractor) {}

    /**
     * Kick off asynchronous extraction for a freshly uploaded receipt.
     */
    public function startProcessing(Expense $expense): void
    {
        $expense->forceFill([
            'processing_status' => ExpenseProcessingStatus::Pending->value,
        ])->save();

        ExpenseProcessingUpdated::dispatch(
            $expense->business_entity_id,
            $expense->getKey(),
            ExpenseProcessingStatus::Pending->value,
            $expense->vendor,
        );

        ProcessReceiptUpload::dispatch($expense->getKey());
    }

    public function markProcessing(Expense $expense): void
    {
        $expense->forceFill([
            'processing_status' => ExpenseProcessingStatus::Processing->value,
        ])->save();

        $this->broadcast($expense);
    }

    /**
     * Read the stored receipt and apply the extracted data. Throws on failure so
     * the job can retry / eventually mark the expense failed.
     */
    public function runExtraction(Expense $expense): void
    {
        $disk = Storage::disk('receipts');
        $path = (string) $expense->receipt_path;

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        $contents = (string) $disk->get($path);

        $result = $this->extractor->extract($contents, $mime);

        $category = $this->matchCategory($result->categoryHint);
        $amount = round((float) ($result->totalAmount ?? 0), 2);
        $vat = round((float) ($result->vatAmount ?? 0), 2);
        $net = round(max(0, $amount - $vat), 2);
        $deductibility = $category?->default_deductibility ?? DeductibilityStatus::Uncertain;

        $expense->forceFill([
            'processing_status' => ExpenseProcessingStatus::Extracted->value,
            'processing_error' => null,
            'vendor' => $result->vendorName ?: $expense->vendor,
            'expense_date' => $result->documentDate ?: $expense->expense_date,
            'amount' => $amount,
            'vat_amount' => $vat,
            'vat_rate' => $result->vatRate ?? 0,
            'net_amount' => $net,
            'currency_code' => $result->currency ?: $expense->currency_code,
            'ai_suggested_category_id' => $category?->getKey(),
            // Don't clobber a category the user already chose by hand.
            'category_id' => $expense->user_overrode_category ? $expense->category_id : $category?->getKey(),
            'deductibility' => $deductibility->value,
            'deductible_pct' => $deductibility->defaultPercent(),
            'ocr_processed_at' => now(),
            'ocr_raw_data' => $result->toArray(),
            'ocr_confidence' => $result->confidence,
            'ai_confidence' => $result->confidence,
        ])->save();

        $this->broadcast($expense);
        $this->notifyOwner($expense, success: true);
    }

    public function markFailed(Expense $expense, string $error): void
    {
        $expense->forceFill([
            'processing_status' => ExpenseProcessingStatus::Failed->value,
            'processing_error' => Str::limit($error, 500),
        ])->save();

        $this->broadcast($expense);
        $this->notifyOwner($expense, success: false);
    }

    /**
     * Confirm a reviewed expense: recompute the deductible amount from the
     * amount and percentage, mark it reviewed, and refresh the tax estimate.
     */
    public function confirm(Expense $expense): Expense
    {
        $pct = $expense->deductible_pct !== null
            ? (float) $expense->deductible_pct
            : ($expense->deductibility?->defaultPercent() ?? 0.0);

        $deductible = round((float) $expense->amount * $pct / 100, 2);

        $expense->forceFill([
            'status' => ExpenseStatus::Reviewed->value,
            'deductible_pct' => $pct,
            'deductible_amount' => $deductible,
        ])->save();

        RecalculateTaxEstimation::dispatch($expense->business_entity_id);

        return $expense;
    }

    /**
     * Recompute the deductible amount from the current amount and percentage.
     * If the expense is already confirmed, refresh the tax estimate too.
     */
    public function recomputeDeductible(Expense $expense): void
    {
        $pct = $expense->deductible_pct !== null
            ? (float) $expense->deductible_pct
            : ($expense->deductibility?->defaultPercent() ?? 0.0);

        $expense->forceFill([
            'deductible_pct' => $pct,
            'deductible_amount' => round((float) $expense->amount * $pct / 100, 2),
        ])->save();

        if ($expense->status === ExpenseStatus::Reviewed) {
            RecalculateTaxEstimation::dispatch($expense->business_entity_id);
        }
    }

    /**
     * Best-effort match of a free-text category hint to a seeded category.
     */
    public function matchCategory(?string $hint): ?ExpenseCategory
    {
        $needle = Str::lower(trim((string) $hint));

        if ($needle === '') {
            return null;
        }

        return ExpenseCategory::query()
            ->where('is_active', true)
            ->where(function ($query) use ($needle) {
                $query->whereRaw('LOWER(code) LIKE ?', ["%{$needle}%"])
                    ->orWhereRaw('LOWER(name_en) LIKE ?', ["%{$needle}%"]);
            })
            ->orderBy('sort_order')
            ->first();
    }

    private function broadcast(Expense $expense): void
    {
        ExpenseProcessingUpdated::dispatch(
            $expense->business_entity_id,
            $expense->getKey(),
            $expense->processing_status->value,
            $expense->vendor,
        );
    }

    private function notifyOwner(Expense $expense, bool $success): void
    {
        $owner = $expense->businessEntity()->first()?->owner()->first();

        if ($owner === null) {
            return;
        }

        Notification::make()
            ->title($success ? 'Receipt processed' : 'Receipt could not be read')
            ->body($success
                ? trim(($expense->vendor ?: 'A receipt').' is ready for review.')
                : 'Enter the details manually or try uploading again.')
            ->icon($success ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle')
            ->status($success ? 'success' : 'warning')
            ->sendToDatabase($owner);
    }
}
