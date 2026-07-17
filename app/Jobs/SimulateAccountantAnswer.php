<?php

namespace App\Jobs;

use App\Enums\AiEscalationStatus;
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
 */
class SimulateAccountantAnswer implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

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
        $escalation = AiEscalation::find($this->escalationId);

        if ($escalation === null || $escalation->status !== AiEscalationStatus::Pending) {
            return;
        }

        $escalations->applyAnswer($escalation, self::MARIA_ANSWER);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('Simulated accountant answer failed', [
            'escalation_id' => $this->escalationId,
        ]);
    }
}
