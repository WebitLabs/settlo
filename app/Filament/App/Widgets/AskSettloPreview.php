<?php

namespace App\Filament\App\Widgets;

use App\Models\AiConversation;
use App\Models\BusinessEntity;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Str;

/**
 * Dashboard preview card for Ask Settlo: surfaces the most recent conversation's
 * last question and answer, a link into the full chat, and two quick-question
 * chips that deep-link into the chat pre-filled. Strictly tenant-scoped to the
 * currently active business entity.
 */
class AskSettloPreview extends Widget
{
    protected string $view = 'filament.app.widgets.ask-settlo-preview';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    /**
     * Quick-question chips deep-linked into the chat, verbatim from the backlog
     * (SETTLO-21).
     *
     * @var list<string>
     */
    private const array QUICK_QUESTIONS = [
        'Can I deduct this?',
        'How much to reserve?',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $entity = Filament::getTenant();

        $conversation = $entity instanceof BusinessEntity
            ? $this->latestConversation($entity)
            : null;

        return [
            'chatUrl' => $entity instanceof BusinessEntity
                ? route('ask-settlo.index', $entity)
                : null,
            'hasConversation' => $conversation !== null,
            'title' => $conversation?->title,
            'question' => $conversation !== null
                ? $this->lastMessageContent($conversation, 'user')
                : null,
            'answer' => $conversation !== null
                ? Str::limit($this->lastMessageContent($conversation, 'assistant') ?? '', 140)
                : null,
            'quickQuestions' => self::QUICK_QUESTIONS,
        ];
    }

    private function latestConversation(BusinessEntity $entity): ?AiConversation
    {
        return AiConversation::query()
            ->where('business_entity_id', $entity->getKey())
            ->where('user_id', auth()->id())
            ->whereHas('messages', fn ($query) => $query->where('role', 'assistant'))
            ->latest('updated_at')
            ->first();
    }

    private function lastMessageContent(AiConversation $conversation, string $role): ?string
    {
        return $conversation->messages()
            ->where('role', $role)
            ->latest('created_at')
            ->value('content');
    }
}
