<?php

namespace App\Models;

use App\Enums\AiEscalationStatus;
use App\Enums\AiQuestionCategory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiEscalation extends Model
{
    use HasUuids;

    /**
     * status / accountant fields / SLA are managed by the escalation service.
     *
     * @var list<string>
     */
    protected $fillable = [
        'conversation_id', 'message_id', 'user_id', 'accounting_firm_id',
        'category', 'user_question', 'ai_answer', 'sla_deadline',
    ];

    protected function casts(): array
    {
        return [
            'status' => AiEscalationStatus::class,
            'category' => AiQuestionCategory::class,
            'answered_at' => 'datetime',
            'resolved_at' => 'datetime',
            'add_to_knowledge_base' => 'boolean',
            'knowledge_base_approved_at' => 'datetime',
            'sla_deadline' => 'datetime',
            'sla_breached' => 'boolean',
        ];
    }

    /** @return BelongsTo<AiConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AiConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<AiMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(AiMessage::class, 'message_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function accountant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accountant_id');
    }

    /** @return BelongsTo<AccountingFirm, $this> */
    public function accountingFirm(): BelongsTo
    {
        return $this->belongsTo(AccountingFirm::class);
    }
}
