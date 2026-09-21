<?php

namespace App\Jobs;

use App\Enums\AiEscalationStatus;
use App\Models\AccountantAssignment;
use App\Models\AiEscalation;
use App\Services\Ai\EscalationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POC stand-in for a real accountant reply. Runs on the Horizon "ai" queue a few
 * seconds after an escalation is raised and writes the canned Maria Schneider
 * answer through the EscalationService. Carries only the escalation UUID so it
 * runs correctly on any worker and never serialises a model or user.
 *
 * The stand-in exists only for businesses with no accounting firm assigned. An
 * escalation that already belongs to a firm is a real queue item waiting for a
 * human, so the job re-checks that here as well as at dispatch time: an
 * assignment may have been created while the job sat on the queue.
 */
class SimulateAccountantAnswer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @var array<int, int> */
    public array $backoff = [10, 30];

    /**
     * Canned accountant answer, verbatim from the product backlog (SETTLO-20).
     */
    private const MARIA_ANSWER = "Settlo AI's answer is correct. I'd add one practical note: if any single invoice exceeds CHF 100,000 on its own, that triggers mandatory VAT registration immediately, regardless of your YTD total. I recommend starting the ESTV application at least 6 weeks before your target start date and can assist if needed. — Maria Schneider";

    public function __construct(public string $escalationId)
    {
        $this->onQueue('ai');
    }

    public function handle(EscalationService $escalations): void
    {
        if (! config('settlo.escalation.simulate_answer', true)) {
            return;
        }

        $escalation = AiEscalation::find($this->escalationId);

        if ($escalation === null || $escalation->status !== AiEscalationStatus::Pending) {
            return;
        }

        if ($escalation->accounting_firm_id !== null || $this->hasAssignedFirm($escalation)) {
            return;
        }

        $escalations->applyAnswer($escalation, self::MARIA_ANSWER);
    }

    /**
     * Whether a firm has been assigned to the business behind the escalation
     * since it was raised — in which case a real human owns the answer.
     */
    private function hasAssignedFirm(AiEscalation $escalation): bool
    {
        $entityId = $escalation->conversation()->value('business_entity_id');

        if ($entityId === null) {
            return false;
        }

        return AccountantAssignment::query()
            ->where('business_entity_id', $entityId)
            ->whereNull('revoked_at')
            ->exists();
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Simulated accountant answer failed', [
            'escalation_id' => $this->escalationId,
            'exception' => $exception->getMessage(),
        ]);
    }
}
