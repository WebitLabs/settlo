<?php

namespace App\Http\Controllers\AskSettlo;

use App\Billing\QuotaExceededException;
use App\Enums\AiEscalationStatus;
use App\Enums\PlanFeature;
use App\Http\Controllers\Controller;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\BusinessEntity;
use App\Models\User;
use App\Services\Ai\AskSettloService;
use App\Services\Ai\ChatContextAssembler;
use App\Services\Ai\EscalationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HTTP surface for Ask Settlo. Every action re-derives the tenant boundary from
 * the route's business entity and the authenticated owner — a conversation,
 * message or escalation id from the client is always checked to belong to that
 * owner + entity before any work happens. Controllers stay thin: the chat and
 * escalation domain logic lives in the services.
 */
class AskSettloController extends Controller
{
    /**
     * The fixed pool of eight suggested questions (per spec); the client samples
     * four at random per empty conversation.
     *
     * @var list<string>
     */
    private const array SUGGESTED_QUESTIONS = [
        'Do I need to register for VAT?',
        'How is AHV calculated?',
        'Can I deduct home office costs?',
        'What is the Pillar 3a maximum?',
        'How does the communal tax multiplier work?',
        'Can I deduct client gifts?',
        'What expenses are 100% deductible?',
        'How do I file my Swiss tax declaration?',
    ];

    public function index(Request $request, BusinessEntity $businessEntity, ChatContextAssembler $assembler): InertiaResponse
    {
        $user = $this->authorizeEntityAccess($request, $businessEntity);

        $conversations = $this->conversationsFor($user, $businessEntity);
        $active = $conversations->first();

        return Inertia::render('AskSettlo/Index', [
            'businessEntityId' => $businessEntity->getKey(),
            'conversations' => $conversations->map(fn (AiConversation $c): array => $this->presentConversationSummary($c))->values(),
            'activeConversation' => $active !== null ? $this->presentConversation($active) : null,
            'context' => $this->presentContext($assembler, $user, $businessEntity),
            'quota' => $this->presentQuota($user),
            'accountant' => $this->accountantCard(),
            'suggestedQuestions' => self::SUGGESTED_QUESTIONS,
        ]);
    }

    public function storeConversation(Request $request, BusinessEntity $businessEntity, AskSettloService $service): JsonResponse
    {
        $user = $this->authorizeEntityAccess($request, $businessEntity);
        Gate::authorize('create', AiConversation::class);

        $conversation = $service->startConversation($user, $businessEntity);

        return response()->json($this->presentConversation($conversation), 201);
    }

    public function showConversation(Request $request, BusinessEntity $businessEntity, AiConversation $conversation): JsonResponse
    {
        $this->authorizeEntityAccess($request, $businessEntity);
        $this->authorizeConversation($businessEntity, $conversation);

        return response()->json($this->presentConversation($conversation));
    }

    public function storeMessage(Request $request, BusinessEntity $businessEntity, AiConversation $conversation, AskSettloService $service): JsonResponse
    {
        $this->authorizeEntityAccess($request, $businessEntity);
        $this->authorizeConversation($businessEntity, $conversation);

        $content = $this->validatedContent($request);
        $assistant = $service->sendMessage($conversation, $content);

        return response()->json($this->presentMessage($assistant->fresh()));
    }

    public function stream(Request $request, BusinessEntity $businessEntity, AiConversation $conversation, AskSettloService $service): StreamedResponse
    {
        $user = $this->authorizeEntityAccess($request, $businessEntity);
        $this->authorizeConversation($businessEntity, $conversation);

        $content = $this->validatedContent($request);
        ['reply' => $reply, 'snapshot' => $snapshot] = $service->prepareReply($conversation, $content);
        $canEscalate = $user->hasFeature(PlanFeature::AccountantAccess);

        return response()->stream(function () use ($service, $conversation, $content, $reply, $snapshot, $canEscalate): void {
            foreach ($reply->chunks() as $chunk) {
                $this->emit('delta', ['text' => $chunk]);
            }

            $message = $service->persistAssistantMessage($conversation, $content, $reply, $snapshot);

            $this->emit('done', [
                'id' => $message->getKey(),
                'confidence' => $message->confidence !== null ? (float) $message->confidence : null,
                'canEscalate' => $canEscalate,
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function escalate(Request $request, BusinessEntity $businessEntity, AiMessage $message, EscalationService $escalations): JsonResponse
    {
        $user = $this->authorizeEntityAccess($request, $businessEntity);

        $conversation = $message->conversation()->firstOrFail();
        $this->authorizeConversation($businessEntity, $conversation);

        try {
            $escalation = $escalations->escalate($message, $user);
        } catch (QuotaExceededException) {
            return response()->json([
                'message' => 'Monthly limit reached — upgrade to Confidence for 3 answers/month.',
            ], 429);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        }

        return response()->json([
            'escalation' => $this->presentEscalation($escalation),
            'quota' => $this->presentQuota($user->fresh()),
        ], 201);
    }

    public function resolve(Request $request, BusinessEntity $businessEntity, AiEscalation $escalation, EscalationService $escalations): JsonResponse
    {
        $user = $this->authorizeEntityAccess($request, $businessEntity);

        $conversation = $escalation->conversation()->firstOrFail();
        $this->authorizeConversation($businessEntity, $conversation);
        Gate::authorize('resolve', $escalation);

        $escalation = $escalations->markResolved($escalation, $user);

        return response()->json($this->presentEscalation($escalation));
    }

    /**
     * The owner's conversations within this entity, newest activity first, with
     * the relations needed to render list badges and message threads.
     *
     * @return Collection<int, AiConversation>
     */
    private function conversationsFor(User $user, BusinessEntity $entity): Collection
    {
        return AiConversation::query()
            ->where('user_id', $user->getKey())
            ->where('business_entity_id', $entity->getKey())
            ->with(['messages.escalation', 'escalations'])
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Confirm the request comes from the owner of this business entity and that
     * their subscription still grants access, then return the user.
     */
    private function authorizeEntityAccess(Request $request, BusinessEntity $entity): User
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->isOwner() && $entity->owner_id === $user->getKey(), 403);
        abort_unless($user->subscription?->grantsAccess() ?? false, 403);

        return $user;
    }

    /**
     * A conversation reached through a route must belong to this entity and this
     * owner — otherwise it is treated as not found, never leaked.
     */
    private function authorizeConversation(BusinessEntity $entity, AiConversation $conversation): void
    {
        abort_unless(
            $conversation->business_entity_id === $entity->getKey()
                && $conversation->user_id === request()->user()?->getKey(),
            404,
        );
    }

    private function validatedContent(Request $request): string
    {
        return (string) $request->validate([
            'content' => ['required', 'string', 'max:4000'],
        ])['content'];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConversation(AiConversation $conversation): array
    {
        $conversation->loadMissing(['messages.escalation']);

        return [
            'id' => $conversation->getKey(),
            'title' => $conversation->title ?? 'New conversation',
            'messages' => $conversation->messages
                ->map(fn (AiMessage $m): array => $this->presentMessage($m))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentConversationSummary(AiConversation $conversation): array
    {
        $last = $conversation->messages->last();

        return [
            'id' => $conversation->getKey(),
            'title' => $conversation->title ?? 'New conversation',
            'preview' => $last !== null ? Str::limit((string) $last->content, 60) : 'No messages yet',
            'group' => $this->dateGroup($conversation->updated_at),
            'badge' => $this->conversationBadge($conversation),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMessage(AiMessage $message): array
    {
        return [
            'id' => $message->getKey(),
            'role' => $message->role,
            'content' => $message->content,
            'confidence' => $message->confidence !== null ? (float) $message->confidence : null,
            'createdAt' => $message->created_at?->toIso8601String(),
            'escalation' => $message->escalation !== null
                ? $this->presentEscalation($message->escalation)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEscalation(AiEscalation $escalation): array
    {
        return [
            'id' => $escalation->getKey(),
            'messageId' => $escalation->message_id,
            'status' => $escalation->status->value,
            'question' => $escalation->user_question,
            'answer' => $escalation->accountant_answer,
            'notes' => $escalation->accountant_notes,
            'accountantName' => $escalation->accountant()->value('name') ?? 'Maria Schneider',
            'answeredAt' => $escalation->answered_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentContext(ChatContextAssembler $assembler, User $user, BusinessEntity $entity): array
    {
        $snapshot = $assembler->assemble($user, $entity)->snapshot;

        return [
            'cantonCode' => $snapshot['canton_code'] ?? 'CH',
            'revenueYtd' => number_format((float) ($snapshot['revenue_ytd'] ?? 0), 0, '.', "'"),
            'vatStatus' => $snapshot['vat_status_label'] ?? 'Not registered',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentQuota(User $user): array
    {
        $subscription = $user->subscription;

        $used = (int) ($subscription?->human_answers_used ?? 0);
        $total = (int) ($subscription?->human_answers_quota ?? 0);

        return [
            'used' => $used,
            'total' => $total,
            'remaining' => max(0, $total - $used),
            'planName' => $subscription?->plan?->name ?? 'Solo',
            'canEscalate' => $user->hasFeature(PlanFeature::AccountantAccess),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function accountantCard(): array
    {
        return [
            'name' => 'Maria Schneider',
            'initials' => 'MS',
            'role' => 'Certified Swiss accountant · Müller Treuhand AG',
            'availability' => 'Available · responds within 24h',
        ];
    }

    private function conversationBadge(AiConversation $conversation): ?string
    {
        $statuses = $conversation->escalations->pluck('status');

        if ($statuses->contains(AiEscalationStatus::Pending)) {
            return 'pending';
        }

        if ($statuses->contains(AiEscalationStatus::Answered)) {
            return 'answered';
        }

        return null;
    }

    private function dateGroup(?Carbon $timestamp): string
    {
        if ($timestamp === null) {
            return 'earlier';
        }

        if ($timestamp->isToday()) {
            return 'today';
        }

        if ($timestamp->greaterThanOrEqualTo(Carbon::now()->startOfWeek())) {
            return 'week';
        }

        return 'earlier';
    }

    /**
     * Push one server-sent event frame and flush it to the client immediately.
     *
     * @param  array<string, mixed>  $payload
     */
    private function emit(string $event, array $payload): void
    {
        echo 'event: '.$event."\n";
        echo 'data: '.json_encode($payload)."\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }
}
