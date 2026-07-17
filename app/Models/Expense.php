<?php

namespace App\Models;

use App\Enums\DeductibilityStatus;
use App\Enums\ExpenseStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * business_entity_id + receipt_path + OCR/AI metadata are set server-side.
     * deductible_amount is always recomputed from amount × pct on the server.
     *
     * @var list<string>
     */
    protected $fillable = [
        'vendor', 'description', 'amount', 'vat_amount', 'vat_rate', 'net_amount',
        'currency_code', 'expense_date', 'category_id', 'deductibility',
        'deductible_pct', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'deductibility' => DeductibilityStatus::class,
            'amount' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'vat_rate' => 'decimal:3',
            'net_amount' => 'decimal:2',
            'deductible_amount' => 'decimal:2',
            'deductible_pct' => 'decimal:2',
            'expense_date' => 'date',
            'ocr_processed_at' => 'datetime',
            'ocr_raw_data' => 'array',
            'ocr_confidence' => 'decimal:4',
            'ai_confidence' => 'decimal:4',
            'user_overrode_category' => 'boolean',
            'user_overrode_deductibility' => 'boolean',
        ];
    }

    /** @return BelongsTo<BusinessEntity, $this> */
    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class);
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function aiSuggestedCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'ai_suggested_category_id');
    }
}
