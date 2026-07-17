<?php

namespace App\Services\Ai;

use App\Enums\AiEscalationStatus;
use App\Enums\PlanFeature;
use App\Events\AiEscalationUpdated;
use App\Jobs\SimulateAccountantAnswer;
use App\Models\AccountantAssignment;
use App\Models\AiConversation;
use App\Models\AiEscalation;
use App\Models\AiMessage;
use App\Models\User;
use App\Services\Billing\SubscriptionService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Owns the accountant-escalation lifecycle for Ask Settlo: raising an escalation
 * from an assistant answer (feature-gated and quota-metered), applying the
 * accountant's reply, and resolving it. All state columns are guarded and set
 * server-side via forceFill — never mass-assigned from input. Every write
 * broadcasts a scalar-only event so the chat UI can update live.
 */
class EscalationService
{
    /**
     * A working day is 09:00–17:00 Europe/Zurich (8 hours); a 24 business-hour
     * SLA therefore lands three working days out, skipping weekends.
     */
    private const BUSINESS_HOUR_START = 9;

    private const BUSINESS_HOUR_END = 17;

    private const SLA_BUSINESS_HOURS = 24;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Raise an accountant escalation for an assistant answer. Requires the
     * AccountantAccess feature and spends one human-answer credit atomically
     * (QuotaExceededException bubbles when the plan quota is exhausted). Creates
     * a pending escalation, broadcasts it, and queues the simulated reply.
     */
    public function escalate(AiMessage $assistantMessage, User $user): AiEscalation
    {
        if (! $assistantMessage->isAssistant()) {
            throw new InvalidArgumentException('Only an assistant answer can be escalated.');
        }

        if (! $user->hasFeature(PlanFeature::AccountantAccess)) {
            throw new AuthorizationException('Human accountant access is not available on your plan.');
        }

        $conversation = $assistantMessage->conversation()->firstOrFail();

        // The duplicate check, credit spend and insert must be one atomic unit.
        // A row lock on the answer serialises two racing requests (double-click /
        // retry) so only the first passes the exists() check — the second sees the
        // existing escalation and never spends a credit. Wrapping everything in a
        // single transaction also guarantees that if the unique message_id insert
        // still loses a race, consumeHumanAnswer's increment is rolled back with
        // it, so a scarce human-answer credit is never burned without an escalation.
        $escalation = DB::transaction(function () use ($assistantMessage, $user, $conversation): AiEscalation {
            AiMessage::whereKey($assistantMessage->getKey())->lockForUpdate()->firstOrFail();

            if ($assistantMessage->escalation()->exists()) {
                throw new RuntimeException('This answer has already been escalated.');
            }

            $subscription = $user->subscription()->firstOrFail();
            $this->subscriptions->consumeHumanAnswer($subscription);

            $escalation = new AiEscalation;
            $escalation->forceFill([
                'conversation_id' => $conversation->getKey(),
                'message_id' => $assistantMessage->getKey(),
                'user_id' => $user->getKey(),
                'accounting_firm_id' => $this->assignedFirmId($conversation),
                'status' => AiEscalationStatus::Pending->value,
                'user_question' => $this->precedingUserQuestion($conversation, $assistantMessage),
                'ai_answer' => $assistantMessage->content,
                'sla_deadline' => $this->slaDeadline(),
            ])->save();

            return $escalation;
        });

        $this->broadcast($escalation, $conversation);

        SimulateAccountantAnswer::dispatch($escalation->getKey())->delay(now()->addSeconds(4));

        return $escalation;
    }

    /**
     * Record the accountant's reply, flag any SLA breach, broadcast the change,
     * and notify the owner in-app. Passing no accountant models the simulated
     * Maria Schneider flow.
     */
    public function applyAnswer(AiEscalation $escalation, string $answer, ?string $notes = null, ?User $accountant = null): AiEscalation
    {
        $answeredAt = Carbon::now();

        $escalation->forceFill([
            'status' => AiEscalationStatus::Answered->value,
            'accountant_answer' => $answer,
            'accountant_notes' => $notes,
            'accountant_id' => $accountant?->getKey(),
            'answered_at' => $answeredAt,
            'sla_breached' => $escalation->sla_deadline !== null
                && $answeredAt->greaterThan($escalation->sla_deadline),
        ])->save();

        $conversation = $escalation->conversation()->firstOrFail();

        $this->broadcast($escalation, $conversation);
        $this->notifyOwner($escalation, $conversation);

        return $escalation;
    }

    /**
     * Owner acknowledges the answer and closes the escalation. Only an already
     * Answered escalation may be resolved: closing a still-Pending one would
     * strand it after the human-answer credit was already burnt.
     *
     * @throws InvalidArgumentException when the escalation has not been answered yet
     */
    public function markResolved(AiEscalation $escalation, User $user): AiEscalation
    {
        if ($escalation->status !== AiEscalationStatus::Answered) {
            throw new InvalidArgumentException('Only an answered escalation can be resolved.');
        }

        $escalation->forceFill([
            'status' => AiEscalationStatus::Closed->value,
            'resolved_at' => Carbon::now(),
        ])->save();

        $conversation = $escalation->conversation()->firstOrFail();
        $this->broadcast($escalation, $conversation);

        return $escalation;
    }

    private function broadcast(AiEscalation $escalation, AiConversation $conversation): void
    {
        AiEscalationUpdated::dispatch(
            $conversation->business_entity_id,
            $escalation->getKey(),
            $conversation->getKey(),
            $escalation->status->value,
            $escalation->answered_at?->toIso8601String(),
        );
    }

    private function notifyOwner(AiEscalation $escalation, AiConversation $conversation): void
    {
        $owner = $conversation->businessEntity()->first()?->owner()->first();

        if ($owner === null) {
            return;
        }

        $title = filled($conversation->title) ? $conversation->title : 'your question';

        Notification::make()
            ->title('Your accountant answered')
            ->body("A verified answer to \"{$title}\" is ready in Ask Settlo.")
            ->icon('heroicon-o-check-badge')
            ->status('success')
            ->actions([
                Action::make('view')
                    ->label('Open Ask Settlo')
                    ->url(url('/app/'.$conversation->business_entity_id)),
            ])
            ->sendToDatabase($owner);
    }

    /**
     * The user message that immediately precedes the escalated assistant answer.
     */
    private function precedingUserQuestion(AiConversation $conversation, AiMessage $assistantMessage): string
    {
        return (string) $conversation->messages()
            ->where('role', 'user')
            ->where('created_at', '<=', $assistantMessage->created_at)
            ->orderByDesc('created_at')
            ->value('content');
    }

    private function assignedFirmId(AiConversation $conversation): ?string
    {
        return AccountantAssignment::query()
            ->where('business_entity_id', $conversation->business_entity_id)
            ->whereNull('revoked_at')
            ->value('accounting_firm_id');
    }

    private function slaDeadline(): Carbon
    {
        return $this->addBusinessHours(Carbon::now('Europe/Zurich'), self::SLA_BUSINESS_HOURS);
    }

    /**
     * Advance a starting instant by whole business hours, counting only clock
     * hours that begin on a weekday within the 09:00–17:00 Europe/Zurich window.
     */
    private function addBusinessHours(Carbon $start, int $hours): Carbon
    {
        $cursor = $start->copy()->startOfHour();
        $counted = 0;

        while ($counted < $hours) {
            if ($this->isBusinessHour($cursor)) {
                $counted++;
            }

            $cursor->addHour();
        }

        return $cursor;
    }

    private function isBusinessHour(Carbon $moment): bool
    {
        return $moment->isWeekday()
            && $moment->hour >= self::BUSINESS_HOUR_START
            && $moment->hour < self::BUSINESS_HOUR_END;
    }
}
