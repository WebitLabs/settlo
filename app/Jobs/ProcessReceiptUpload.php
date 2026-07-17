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
