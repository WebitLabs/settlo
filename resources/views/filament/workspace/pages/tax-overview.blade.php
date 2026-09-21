<x-filament-panels::page>
    @php($estimation = $this->getEstimation())

    @include('filament.workspace.partials.pending-expenses-notice', ['notice' => $this->getPendingExpensesNotice()])

    @if (! $estimation)
        <x-filament::section>
            <div class="mx-auto max-w-md py-10 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-white/5">
                    <x-filament::icon icon="heroicon-o-calculator" class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                </div>
                <p class="mt-4 text-base font-semibold text-gray-950 dark:text-white">No tax estimate yet</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Add revenue and confirmed expenses, then choose <strong class="font-medium text-gray-700 dark:text-gray-300">Recalculate</strong>.
                </p>
            </div>
        </x-filament::section>
    @else
        @php($vat = $this->getVatEvaluation())
        @php($vatPct = min(100, max(0, (float) ($vat['progress_pct'] ?? 0))))
        @php($vatColor = $vatPct >= 90 ? 'danger' : ($vatPct >= 60 ? 'warning' : 'primary'))
        @php($threshold = \App\Services\Tax\VatAlertCopy::money($vat['threshold'] ?? 100000))

        <div class="grid gap-6 md:grid-cols-4">
            <x-filament::section>
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-banknotes" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    Tax owed so far · {{ $estimation->fiscal_year }}
                </div>
                <div class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    CHF {{ number_format((float) $estimation->total_tax_burden, 2, '.', "'") }}
                </div>
                <div class="mt-2">
                    <x-filament::badge color="gray" size="sm">
                        {{ number_format((float) $estimation->effective_rate, 1) }}% effective rate
                    </x-filament::badge>
                </div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Year to date, on invoices and confirmed expenses so far.
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-calendar-days" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    Expected for the full year
                </div>
                <div class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    CHF {{ number_format((float) $estimation->projected_total_tax, 2, '.', "'") }}
                </div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    On a projected revenue of CHF {{ number_format((float) $estimation->projected_annual_revenue, 0, '.', "'") }},
                    from {{ $estimation->inputs['days_elapsed'] ?? 0 }} days of trading.
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-arrow-trending-up" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    Set aside monthly
                </div>
                <div class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    CHF {{ number_format((float) $estimation->projected_monthly_reserve, 2, '.', "'") }}
                </div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    One twelfth of the full-year estimate, not of the amount owed so far.
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-receipt-percent" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    VAT threshold
                </div>
                <div class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    {{ number_format($vatPct, 0) }}%
                </div>
                <div class="mt-3 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                    <div @class([
                        'h-full rounded-full transition-all',
                        'bg-primary-500' => $vatColor === 'primary',
                        'bg-warning-500' => $vatColor === 'warning',
                        'bg-danger-500' => $vatColor === 'danger',
                    ]) style="width: {{ $vatPct }}%"></div>
                </div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    of CHF {{ $threshold }}
                    @if (($vat['scope'] ?? null) === 'owner' && array_key_exists('own_pct', $vat))
                        · all of your sole proprietorships together; this one contributes {{ number_format((float) $vat['own_pct'], 1) }}%
                    @endif
                </div>
                @if ($this->getVatMessage())
                    <p class="mt-2 text-sm font-medium text-gray-950 dark:text-white">{{ $this->getVatMessage() }}</p>
                @endif
            </x-filament::section>
        </div>

        @php($share = $this->getSharePercent())
        <div class="flex items-start gap-3 rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-900 dark:border-primary-500/20 dark:bg-primary-500/10 dark:text-primary-200">
            <x-filament::icon icon="heroicon-m-user" class="mt-px h-5 w-5 shrink-0" />
            <p>
                Income tax and AHV are levied on you personally.
                This business is {{ $share }}&nbsp;% of your self-employment income
                → CHF {{ number_format((float) $estimation->total_tax_burden, 0, '.', "'") }} of your estimated personal tax.
                <a href="{{ $this->getPersonalTaxUrl() }}" class="font-semibold underline">See your full personal tax →</a>
            </p>
        </div>

        @include('filament.shared.tax-breakdown', [
            'estimation' => $estimation,
            'communalEstimated' => $this->isCommunalMultiplierEstimated(),
            'heading' => 'Breakdown (this business)',
            'description' => 'This business\' share of your personal tax.',
        ])
    @endif
</x-filament-panels::page>
