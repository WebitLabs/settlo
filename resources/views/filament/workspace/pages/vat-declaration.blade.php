<x-filament-panels::page>
    @php
        $money = fn (mixed $value): string => \App\Support\Money::format($value);
        $return = $this->getReturn();
        $registered = $this->isRegistered();
    @endphp

    @include('filament.workspace.partials.pending-expenses-notice', ['notice' => $this->getPendingExpensesNotice()])

    @unless ($registered)
        <div class="flex items-start gap-2 rounded-xl bg-info-50 px-4 py-3 text-sm text-info-700 ring-1 ring-info-600/10 dark:bg-info-400/10 dark:text-info-300 dark:ring-info-400/20">
            <x-filament::icon icon="heroicon-m-information-circle" class="mt-0.5 h-5 w-5 shrink-0" />
            <span>
                Your business is not VAT-registered, so you do not file a Form 300 yet. These figures show what a return
                would look like if you registered.
            </span>
        </div>
    @endunless

    <x-filament::section>
        <x-slot name="heading">Reporting period</x-slot>
        <x-slot name="description">Quarterly is the standard cadence for the effective method; half-yearly applies to the net tax rate method.</x-slot>

        <div class="grid gap-4 sm:grid-cols-2">
            <label class="space-y-1.5">
                <span class="text-sm font-medium text-gray-950 dark:text-white">Period</span>
                <select wire:model.live="period" class="fi-select-input block w-full rounded-lg border-none bg-white py-1.5 pe-8 ps-3 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm">
                    @foreach ($this->getPeriodOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label class="space-y-1.5">
                <span class="text-sm font-medium text-gray-950 dark:text-white">Year</span>
                <select wire:model.live="year" class="fi-select-input block w-full rounded-lg border-none bg-white py-1.5 pe-8 ps-3 text-base text-gray-950 shadow-sm ring-1 ring-gray-950/10 transition focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/20 sm:text-sm">
                    @foreach ($this->getYearOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </x-filament::section>

    @if ($return)
        <x-filament::section>
            <x-slot name="heading">
                <span class="inline-flex items-center gap-2">
                    {{ $return->isRefund() ? 'VAT refundable to you' : 'VAT payable' }}
                    <span class="text-gray-400 dark:text-gray-500">· {{ $return->period->label }}</span>
                </span>
            </x-slot>

            <div class="grid gap-6 sm:grid-cols-3">
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Output VAT (sales)</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($return->outputVat) }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">On {{ $money($return->turnoverNet) }} turnover, {{ $return->invoiceCount }} {{ Str::plural('invoice', $return->invoiceCount) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Input VAT (purchases)</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($return->inputVat) }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">From {{ $return->expenseCount }} confirmed {{ Str::plural('expense', $return->expenseCount) }}</p>
                </div>
                <div>
                    <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $return->isRefund() ? 'Net refund' : 'Net payable' }}</p>
                    <p @class([
                        'mt-1 text-2xl font-semibold tabular-nums',
                        'text-success-600 dark:text-success-400' => $return->isRefund(),
                        'text-gray-950 dark:text-white' => ! $return->isRefund(),
                    ])>{{ $money($return->absoluteNetPayable()) }}</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Output VAT less input VAT</p>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Turnover per VAT rate</x-slot>
            <x-slot name="description">Invoices issued in the period ({{ implode(', ', \App\Services\Reporting\VatReturn::issuedStatusLabels()) }}). Drafts and cancelled invoices are excluded.</x-slot>

            @if ($return->outputRows === [])
                <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No invoices were issued in this period.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-4 py-2.5 text-left font-medium">Rate</th>
                            <th class="px-4 py-2.5 text-right font-medium">Turnover (net)</th>
                            <th class="px-4 py-2.5 text-right font-medium">VAT owed</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($return->outputRows as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $row['rate'] }}%</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $money($row['base']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $money($row['vat']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-200 dark:border-white/10">
                            <td class="px-4 py-3 text-base font-semibold text-gray-950 dark:text-white">Total</td>
                            <td class="px-4 py-3 text-right text-base font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($return->turnoverNet) }}</td>
                            <td class="px-4 py-3 text-right text-base font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($return->outputVat) }}</td>
                        </tr>
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Input tax per VAT rate</x-slot>
            <x-slot name="description">Confirmed expenses dated in the period. Expenses awaiting confirmation are not included.</x-slot>

            @if ($return->inputRows === [])
                <p class="py-6 text-center text-sm text-gray-500 dark:text-gray-400">No confirmed expenses in this period.</p>
            @else
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-4 py-2.5 text-left font-medium">Rate</th>
                            <th class="px-4 py-2.5 text-right font-medium">Purchases (net)</th>
                            <th class="px-4 py-2.5 text-right font-medium">Input VAT</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($return->inputRows as $row)
                            <tr>
                                <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $row['rate'] }}%</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $money($row['base']) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $money($row['vat']) }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-gray-200 dark:border-white/10">
                            <td class="px-4 py-3 text-base font-semibold text-gray-950 dark:text-white">Total</td>
                            <td class="px-4 py-3 text-right text-base font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($return->inputBase) }}</td>
                            <td class="px-4 py-3 text-right text-base font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($return->inputVat) }}</td>
                        </tr>
                    </tbody>
                </table>
            @endif

            <p class="mt-4 flex items-start gap-2 text-xs text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-m-information-circle" class="mt-px h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
                <span>
                    These figures prepare your Form 300 — Settlo does not file it for you. Check them against your records before submitting on the AFC portal.
                </span>
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
