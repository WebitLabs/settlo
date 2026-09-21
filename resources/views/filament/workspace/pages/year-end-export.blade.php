<x-filament-panels::page>
    @php
        $money = fn (mixed $value, ?string $currency = null): string => \App\Support\Money::format($value, $currency);
        $counts = $this->getCounts();
        $summary = $this->getSummaryRows();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Fiscal year</x-slot>
        <x-slot name="description">Pick the year to export. The downloads above always follow this selection.</x-slot>

        <label class="block max-w-xs space-y-1.5">
            <span class="text-sm font-medium text-gray-950 dark:text-white">Year</span>
            <select wire:model.live="year" class="fi-select-input block w-full rounded-lg border-none bg-white py-1.5 pe-8 ps-3 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm">
                @foreach ($this->getYearOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </label>

        <div class="mt-6 grid gap-4 sm:grid-cols-2">
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Invoices</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $counts['invoices'] }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Every invoice issued in {{ $this->fiscalYear() }}, drafts and cancelled ones included.</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Expenses</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $counts['expenses'] }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Every expense dated in {{ $this->fiscalYear() }}, with its deductible share.</p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">
            <span class="inline-flex items-center gap-2">
                Totals
                <span class="text-gray-400 dark:text-gray-500">· {{ $this->fiscalYear() }}</span>
            </span>
        </x-slot>
        <x-slot name="description">The figures in the summary CSV, from the same services the dashboard and the VAT declaration use.</x-slot>

        @if ($summary === [])
            <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">Nothing to export for this year yet.</p>
        @else
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($summary as [$label, $amount, $currency])
                        <tr>
                            <td class="px-4 py-3 text-gray-700 dark:text-gray-300">{{ $label }}</td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($amount, $currency) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <p class="mt-4 flex items-start gap-2 text-xs text-gray-500 dark:text-gray-400">
            <x-filament::icon icon="heroicon-m-information-circle" class="mt-px h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
            <span>
                CSV amounts are written as plain decimals (1081.49) and dates as ISO dates, so any spreadsheet or
                accounting tool reads them back unchanged.
            </span>
        </p>
    </x-filament::section>
</x-filament-panels::page>
