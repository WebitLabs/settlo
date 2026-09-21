@php
    $money = fn (float|string|null $value, int $decimals = 0): string => 'CHF '.number_format((float) $value, $decimals, '.', "'");
@endphp

<x-filament-panels::page>
    @if (! $this->hasTaxEngine())
        <x-filament::section>
            <div class="mx-auto max-w-md py-10 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-sparkles" class="h-6 w-6" />
                </div>
                <p class="mt-4 text-base font-semibold text-gray-950 dark:text-white">Your personal tax estimate is part of the Pro plan</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Upgrade any of your businesses to see your income tax, AHV and monthly reserve.
                </p>
                <x-filament::button :href="$this->getBillingUrl()" tag="a" class="mt-4" icon="heroicon-m-credit-card">
                    Go to billing
                </x-filament::button>
            </div>
        </x-filament::section>
    @else
        @php($estimation = $this->getEstimation())
        @php($pendingCount = $this->getPendingExpensesCount())

        @if ($pendingCount > 0)
            <div class="flex items-start gap-2 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
                <x-filament::icon icon="heroicon-m-clock" class="mt-px h-5 w-5 shrink-0" />
                <p>{{ trans_choice(':count expense awaiting confirmation is|:count expenses awaiting confirmation are', $pendingCount, ['count' => $pendingCount]) }} not included below.</p>
            </div>
        @endif

        @if (! $estimation)
            <x-filament::section>
                <div class="mx-auto max-w-md py-10 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-white/5">
                        <x-filament::icon icon="heroicon-o-calculator" class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                    </div>
                    <p class="mt-4 text-base font-semibold text-gray-950 dark:text-white">No personal tax estimate yet</p>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Complete your <a href="{{ $this->getTaxProfileUrl() }}" class="font-medium underline">tax profile</a>, then choose <strong class="font-medium text-gray-700 dark:text-gray-300">Recalculate</strong>.
                    </p>
                </div>
            </x-filament::section>
        @else
            <div class="grid gap-6 md:grid-cols-4">
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Tax owed so far · {{ $estimation->fiscal_year }}</div>
                    <div class="mt-2 text-3xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->total_tax_burden, 2) }}</div>
                    <div class="mt-2">
                        <x-filament::badge color="gray" size="sm">{{ number_format((float) $estimation->effective_rate, 1) }}% effective rate</x-filament::badge>
                    </div>
                    <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">Year to date</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Expected for the full year</div>
                    <div class="mt-2 text-3xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->projected_total_tax, 2) }}</div>
                    <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                        On a projected revenue of {{ $money($estimation->projected_annual_revenue) }}, from {{ $estimation->inputs['days_elapsed'] ?? 0 }} days.
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">Set aside monthly</div>
                    <div class="mt-2 text-3xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->projected_monthly_reserve, 2) }}</div>
                    <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">One twelfth of the full-year estimate</div>
                </x-filament::section>
                <x-filament::section>
                    <div class="text-sm text-gray-500 dark:text-gray-400">AHV / IV / EO</div>
                    <div class="mt-2 text-3xl font-semibold tracking-tight tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->total_social_insurance, 2) }}</div>
                    <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">Updated {{ $estimation->calculated_at->diffForHumans() }}</div>
                </x-filament::section>
            </div>

            @php($vat = $this->getVatEvaluation($estimation))
            @if ($vat !== null)
                @php($vatPct = min(100, max(0, (float) ($vat['progress_pct'] ?? 0))))
                <x-filament::section
                    heading="VAT registration threshold"
                    description="One registration per person — all of your sole proprietorships count towards it together."
                >
                    <x-slot name="afterHeader">
                        <x-filament::badge :color="\App\Services\Tax\VatAlertCopy::color($vat['level'] ?? 'none')">
                            {{ \App\Services\Tax\VatAlertCopy::status($vat['level'] ?? 'none') }}
                        </x-filament::badge>
                    </x-slot>

                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                        <div @class([
                            'h-full rounded-full transition-all',
                            'bg-primary-500' => $vatPct < 60,
                            'bg-warning-500' => $vatPct >= 60 && $vatPct < 90,
                            'bg-danger-500' => $vatPct >= 90,
                        ]) style="width: {{ $vatPct > 0 ? max($vatPct, 2) : 0 }}%"></div>
                    </div>
                    <div class="mt-2 flex items-center justify-between text-xs tabular-nums text-gray-500 dark:text-gray-400">
                        <span>{{ $money($vat['revenue_ytd'] ?? 0) }} so far</span>
                        <span>{{ $money($vat['threshold'] ?? 100000) }}</span>
                    </div>
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        {{ \App\Services\Tax\VatAlertCopy::body($vat) }}
                    </p>
                </x-filament::section>
            @endif

            @php($rows = $this->getBusinessRows($estimation))
            <x-filament::section heading="Per business" description="Each business carries a share of your personal tax proportional to its profit.">
                @if (count($rows) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-gray-200 text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                                    <th class="px-4 py-2.5 text-left font-medium">Business</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Revenue (excl. VAT)</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Deductible expenses</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Profit</th>
                                    <th class="px-4 py-2.5 text-right font-medium">Share</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                                @foreach ($rows as $row)
                                    <tr>
                                        <td class="px-4 py-3 font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $money($row['net_revenue'], 2) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $money($row['deductible_expenses'], 2) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums text-gray-700 dark:text-gray-300">{{ $money($row['net_income'], 2) }}</td>
                                        <td class="px-4 py-3 text-right tabular-nums font-medium text-gray-950 dark:text-white">{{ number_format($row['share'], 1) }} %</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">No sole proprietorship yet.</p>
                @endif
            </x-filament::section>

            @include('filament.shared.tax-breakdown', [
                'estimation' => $estimation,
                'communalEstimated' => $this->isCommunalMultiplierEstimated(),
            ])
        @endif

        @php($comparison = $this->getComparison())
        @php($current = $this->currentCantonCode())
        @php($lowestCode = array_key_first($comparison))

        @if (count($comparison))
            <x-filament::section heading="Canton comparison" description="What your current figures would cost in other cantons.">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs font-medium uppercase tracking-wide text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-4 py-2.5 text-left font-medium">Canton</th>
                            <th class="px-4 py-2.5 text-right font-medium">Total tax</th>
                            <th class="px-4 py-2.5 text-right font-medium">Effective rate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($comparison as $code => $result)
                            <tr @class(['bg-primary-50/60 dark:bg-primary-400/5' => $code === $current])>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-2">
                                        <span @class([
                                            'font-medium',
                                            'text-gray-950 dark:text-white' => $code === $current,
                                            'text-gray-700 dark:text-gray-300' => $code !== $current,
                                        ])>{{ $code }}</span>
                                        @if ($code === $current)
                                            <x-filament::badge color="primary" size="sm">You</x-filament::badge>
                                        @endif
                                        @if ($code === $lowestCode)
                                            <x-filament::badge color="success" size="sm">Lowest</x-filament::badge>
                                        @endif
                                    </span>
                                </td>
                                <td @class([
                                    'px-4 py-3 text-right tabular-nums',
                                    'font-semibold text-gray-950 dark:text-white' => $code === $current,
                                    'text-gray-700 dark:text-gray-300' => $code !== $current,
                                ])>{{ $money($result->totalTaxBurden) }}</td>
                                <td @class([
                                    'px-4 py-3 text-right tabular-nums',
                                    'font-semibold text-gray-950 dark:text-white' => $code === $current,
                                    'text-gray-500 dark:text-gray-400' => $code !== $current,
                                ])>{{ number_format($result->effectiveRate, 1) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
