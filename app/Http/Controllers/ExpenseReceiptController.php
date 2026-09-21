<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a private receipt file only to a user authorised to view its expense.
 * The file lives on a non-public disk and is never exposed by URL guessing.
 *
 * The stored path is treated as untrusted even though it is written
 * server-side: a traversal sequence, an absolute path or another workspace's
 * tenant prefix is refused outright rather than handed to the filesystem, so a
 * corrupted or tampered `receipt_path` can never read outside the receipts
 * disk or across the tenant boundary.
 */
class ExpenseReceiptController
{
    /**
     * Receipts are filed under `receipts/{business_entity_id}/…`. Files written
     * before the per-tenant layout sit directly in `receipts/`; those are still
     * served (the expense policy is the authority), but a path claiming a
     * *different* tenant is always refused.
     */
    private const ROOT_DIRECTORY = 'receipts';

    public function __invoke(Expense $expense): StreamedResponse
    {
        Gate::authorize('view', $expense);

        $path = (string) $expense->receipt_path;

        abort_if(blank($path), 404);
        abort_unless($this->isSafePath($path), 404);
        abort_unless($this->isWithinTenant($path, (string) $expense->business_entity_id), 404);

        $disk = Storage::disk('receipts');

        abort_unless($disk->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: 'bin';

        return $disk->download($path, "receipt-{$expense->getKey()}.{$extension}");
    }

    /**
     * A relative, traversal-free path: no "..", no absolute/UNC root, no
     * backslashes and no null byte.
     */
    private function isSafePath(string $path): bool
    {
        if (str_contains($path, "\0") || str_contains($path, '\\')) {
            return false;
        }

        if (Str::startsWith($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return false;
        }

        return ! in_array('..', explode('/', $path), true);
    }

    /**
     * The path must live under the receipts root, and when it carries a tenant
     * segment that segment must be the expense's own business entity.
     */
    private function isWithinTenant(string $path, string $businessEntityId): bool
    {
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));

        if (($segments[0] ?? null) !== self::ROOT_DIRECTORY) {
            return false;
        }

        // `receipts/file.jpg` — the pre-tenant layout, no prefix to check.
        if (count($segments) < 3) {
            return true;
        }

        return $segments[1] === $businessEntityId;
    }
}
