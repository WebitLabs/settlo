<?php

namespace App\Models;

use App\Enums\AiQuestionCategory;
use App\Enums\VatStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeBaseEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'escalation_id', 'category', 'question', 'answer', 'canton_code',
        'vat_status', 'tags', 'approved_by_id', 'approved_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => AiQuestionCategory::class,
            'vat_status' => VatStatus::class,
            'tags' => 'array',
            'approved_at' => 'datetime',
            'last_used_at' => 'datetime',
            'usage_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<AiEscalation, $this> */
    public function escalation(): BelongsTo
    {
        return $this->belongsTo(AiEscalation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }
}
