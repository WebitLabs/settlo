{{-- Expects $notice: array{count, gross, url}|null (App\Filament\Support\PendingExpensesNotice) --}}
@if ($notice)
    <div class="flex items-start gap-2 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
        <x-filament::icon icon="heroicon-m-clock" class="mt-px h-5 w-5 shrink-0" />
        <p>
            {{ trans_choice(':count expense totalling CHF :gross is|:count expenses totalling CHF :gross are', $notice['count'], [
                'count' => $notice['count'],
                'gross' => number_format((float) $notice['gross'], 2, '.', "'"),
            ]) }}
            awaiting confirmation and not included below.
            <a href="{{ $notice['url'] }}" class="font-semibold underline">Review expenses</a>
        </p>
    </div>
@endif
