<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a private receipt file only to a user authorised to view its expense.
 * The file lives on a non-public disk and is never exposed by URL guessing.
 */
class ExpenseReceiptController
{
    public function __invoke(Expense $expense): StreamedResponse
    {
        Gate::authorize('view', $expense);

        $disk = Storage::disk('receipts');

        abort_if(blank($expense->receipt_path) || ! $disk->exists($expense->receipt_path), 404);

        $extension = pathinfo((string) $expense->receipt_path, PATHINFO_EXTENSION) ?: 'bin';

        return $disk->download($expense->receipt_path, "receipt-{$expense->getKey()}.{$extension}");
    }
}
