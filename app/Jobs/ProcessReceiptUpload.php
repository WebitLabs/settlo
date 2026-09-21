<?php

namespace App\Jobs;

use App\Models\Expense;
use App\Services\Expenses\ExpenseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Extracts data from an uploaded receipt off the request cycle, on the Horizon
 * "files" queue. Carries only the expense id so it runs correctly on any worker.
 */
class ProcessReceiptUpload implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * The extraction call is sized to finish in ~40s worst case (see
     * config/services.php), so 55s leaves room for the download and the write
     * while still fitting a 60s serverless execution budget.
     */
    public int $timeout = 55;

    public bool $failOnTimeout = true;

    public int $maxExceptions = 2;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(public string $expenseId)
    {
        $this->onQueue('files');
    }

    public function handle(ExpenseService $service): void
    {
        $expense = Expense::find($this->expenseId);

        if ($expense === null || blank($expense->receipt_path)) {
            return;
        }

        $service->markProcessing($expense);
        $service->runExtraction($expense); // throws on failure → retry, then failed()
    }

    public function failed(Throwable $exception): void
    {
        $expense = Expense::find($this->expenseId);

        if ($expense !== null) {
            app(ExpenseService::class)->markFailed($expense, $exception->getMessage());
        }
    }
}
