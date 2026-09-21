<?php

namespace App\Services\Expenses;

use App\Enums\DeductibilityStatus;
use App\Enums\ExpenseProcessingStatus;
use App\Enums\ExpenseStatus;
use App\Events\ExpenseProcessingUpdated;
use App\Jobs\ProcessReceiptUpload;
use App\Jobs\RecalculateTaxEstimation;
use App\Models\BusinessEntity;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Services\Extraction\ReceiptExtractor;
use App\Services\Invoicing\InvoiceTotals;
use App\Support\ActionThrottle;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Drives an uploaded receipt through the extraction pipeline and owns the
 * server-authoritative expense mutations. Processing state, OCR/AI metadata and
 * deductible_amount are always set here (guarded columns), never mass-assigned.
 */
class ExpenseService
{
    /** Category code every expense whose category could not be recognised is filed under. */
    public const string FALLBACK_CATEGORY_CODE = 'cat_uncategorised';

    /** Receipts a single workspace may push into extraction per minute. */
    private const int MAX_RECEIPTS_PER_MINUTE = 60;

    public function __construct(private readonly ReceiptExtractor $extractor) {}

    /**
     * Kick off asynchronous extraction for a freshly uploaded receipt.
     */
    public function startProcessing(Expense $expense): void
    {
        // Each receipt costs a third-party extraction call, so the pipeline is
        // throttled per workspace. The upload happens inside a panel action, so
        // there is no route for middleware to throttle.
        ActionThrottle::hit(
            'receipt-ocr',
            (string) $expense->business_entity_id,
            self::MAX_RECEIPTS_PER_MINUTE,
        );

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

        $matched = $this->matchCategory($result->categoryHint);
        // A hint that matches nothing must not leave the expense uncategorised
        // and therefore unconfirmable — it is filed under "Uncategorised", and
        // the AI confidence drops to zero because we did not, in fact,
        // recognise the category.
        $category = $matched ?? self::fallbackCategory();
        $categoryConfidence = $matched !== null ? $result->confidence : 0.0;
        $amount = round((float) ($result->totalAmount ?? 0), 2);
        $vat = round((float) ($result->vatAmount ?? 0), 2);
        $net = round(max(0, $amount - $vat), 2);
        $deductibility = $category->default_deductibility ?? DeductibilityStatus::Uncertain;
        // The category's own default wins over the status default: a pro-rata
        // category such as home office is partially deductible but nowhere near
        // the generic 50 %.
        $deductiblePct = $category->default_deductible_pct !== null
            ? (float) $category->default_deductible_pct
            : $deductibility->defaultPercent();

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
            'ai_suggested_category_id' => $category->getKey(),
            // Don't clobber a category the user already chose by hand.
            'category_id' => $expense->user_overrode_category ? $expense->category_id : $category->getKey(),
            'deductibility' => $deductibility->value,
            'deductible_pct' => $deductiblePct,
            'ocr_processed_at' => now(),
            'ocr_raw_data' => $result->toArray(),
            'ocr_confidence' => $result->confidence,
            'ai_confidence' => $categoryConfidence,
            'ai_reasoning' => self::categoryReasoning($result->categoryHint, $matched, $category),
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
        $pct = self::deductiblePercent($expense);

        $expense->forceFill([
            'status' => ExpenseStatus::Reviewed->value,
            'deductible_pct' => (float) $pct,
            'deductible_amount' => self::deductibleAmount($expense->amount, $pct),
        ])->save();

        RecalculateTaxEstimation::dispatch($expense->business_entity_id);

        return $expense;
    }

    /**
     * An expense can only be confirmed once it has a positive amount and a category.
     */
    public static function canBeConfirmed(Expense $expense): bool
    {
        return filled($expense->category_id)
            && bccomp(InvoiceTotals::numeric($expense->amount), '0', 2) > 0;
    }

    /**
     * Expenses still awaiting confirmation for a fiscal year — they are left out
     * of the tax estimate and the VAT summary until confirmed.
     *
     * @return array{count: int, gross: string}
     */
    public function pendingSummary(BusinessEntity $entity, int $fiscalYear): array
    {
        // Sargable date range rather than whereYear(), so the expense_date
        // index can be used.
        $yearStart = Carbon::create($fiscalYear, 1, 1)->toDateString();
        $nextYearStart = Carbon::create($fiscalYear + 1, 1, 1)->toDateString();

        $amounts = Expense::query()
            ->where('business_entity_id', $entity->getKey())
            ->where('status', ExpenseStatus::PendingReview->value)
            ->where('expense_date', '>=', $yearStart)
            ->where('expense_date', '<', $nextYearStart)
            ->pluck('amount');

        return [
            'count' => $amounts->count(),
            'gross' => $amounts->reduce(
                fn (string $sum, mixed $amount): string => bcadd($sum, InvoiceTotals::numeric($amount), 2),
                '0.00',
            ),
        ];
    }

    /**
     * Recompute the deductible amount from the current amount and percentage.
     * If the expense is already confirmed, refresh the tax estimate too.
     */
    public function recomputeDeductible(Expense $expense): void
    {
        $pct = self::deductiblePercent($expense);

        $expense->forceFill([
            'deductible_pct' => (float) $pct,
            'deductible_amount' => self::deductibleAmount($expense->amount, $pct),
        ])->save();

        if ($expense->status === ExpenseStatus::Reviewed) {
            RecalculateTaxEstimation::dispatch($expense->business_entity_id);
        }
    }

    /**
     * The deductible percentage that applies to an expense: the confirmed one
     * when the user set it, otherwise the category's default for its
     * deductibility status. Returned as a numeric string for exact arithmetic.
     */
    public static function deductiblePercent(Expense $expense): string
    {
        return InvoiceTotals::numeric(
            $expense->deductible_pct ?? $expense->deductibility?->defaultPercent() ?? 0,
        );
    }

    /**
     * Deductible amount: amount × pct / 100, in BCMath at scale 6 and rounded
     * to CHF 0.01 only at the end (spec section 6). Float multiplication was
     * losing a centime on amounts such as 1,234.55 × 33 %.
     */
    public static function deductibleAmount(float|int|string|null $amount, float|int|string|null $pct): string
    {
        return InvoiceTotals::round(bcdiv(
            bcmul(InvoiceTotals::numeric($amount), InvoiceTotals::numeric($pct), 6),
            '100',
            6,
        ));
    }

    /**
     * VAT contained in a VAT-inclusive (gross) amount: gross × rate / (100 + rate),
     * rounded to CHF 0.01. A zero, negative or non-numeric rate yields no VAT.
     */
    public static function vatFromGross(string $gross, string $rate): string
    {
        $gross = InvoiceTotals::numeric($gross);
        $rate = InvoiceTotals::numeric($rate);

        if (bccomp($rate, '0', 6) <= 0) {
            return '0.00';
        }

        return InvoiceTotals::round(bcdiv(bcmul($gross, $rate, 6), bcadd('100', $rate, 6), 6));
    }

    /**
     * Server-side fallback for form data: a blank VAT rate counts as 0 and a
     * blank VAT amount is derived from the gross amount and rate (both columns
     * are NOT NULL).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withDerivedVat(array $data): array
    {
        if (array_key_exists('vat_rate', $data) && blank($data['vat_rate'])) {
            $data['vat_rate'] = 0;
        }

        if (array_key_exists('vat_amount', $data) && blank($data['vat_amount'])) {
            $data['vat_amount'] = is_numeric($data['amount'] ?? null)
                ? self::vatFromGross((string) $data['amount'], (string) ($data['vat_rate'] ?? '0'))
                : 0;
        }

        return $data;
    }

    /**
     * The category every unmatched expense is filed under, so it still has a
     * category and can be confirmed. Created on demand: it is a system row,
     * not one of the seeded Swiss categories.
     */
    public static function fallbackCategory(): ExpenseCategory
    {
        return ExpenseCategory::firstOrCreate(
            ['code' => self::FALLBACK_CATEGORY_CODE],
            [
                'name_en' => 'Uncategorised',
                'name_de' => 'Nicht kategorisiert',
                'name_fr' => 'Non catégorisé',
                'name_it' => 'Non categorizzato',
                'default_deductibility' => DeductibilityStatus::Uncertain,
                'default_deductible_pct' => 0,
                'requires_proof' => true,
                'vat_eligible' => false,
                'legal_basis' => null,
                'notes' => 'Settlo could not recognise the category — review it and pick the right one.',
                'is_active' => true,
                'sort_order' => 999,
            ],
        );
    }

    /**
     * Plain-English record of how the category was chosen, stored on the
     * expense so the user (and support) can see why it was filed that way.
     */
    private static function categoryReasoning(?string $hint, ?ExpenseCategory $matched, ExpenseCategory $category): string
    {
        $hint = trim((string) $hint);

        if ($matched !== null) {
            return "Matched the receipt hint \"{$hint}\" to {$matched->name_en}.";
        }

        return $hint === ''
            ? "The receipt gave no category hint, so it was filed under {$category->name_en} for review."
            : "No category matched the receipt hint \"{$hint}\", so it was filed under {$category->name_en} for review.";
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
            ->where('code', '!=', self::FALLBACK_CATEGORY_CODE)
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
