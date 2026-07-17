<?php

namespace App\Models;

use App\Enums\AiQuestionCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AiMessage extends Model
{
    use HasUuids;

    protected $fillable = [
        'conversation_id', 'role', 'content', 'category',
        'model_used', 'confidence', 'tokens_used', 'processing_ms', 'context_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'category' => AiQuestionCategory::class,
            'confidence' => 'decimal:4',
            'tokens_used' => 'integer',
            'processing_ms' => 'integer',
            'context_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<AiConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /** @return HasOne<AiEscalation, $this> */
    public function escalation(): HasOne
    {
        return $this->hasOne(AiEscalation::class, 'message_id');
    }

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }
}
