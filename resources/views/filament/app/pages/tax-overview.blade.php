<x-filament-panels::page>
    @php($estimation = $this->getEstimation())

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
        @php($vatPct = min(100, max(0, (float) $estimation->vat_threshold_pct)))
        @php($vatColor = $vatPct >= 90 ? 'danger' : ($vatPct >= 60 ? 'warning' : 'primary'))

        <div class="grid gap-6 md:grid-cols-3">
            <x-filament::section>
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-banknotes" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    Total tax burden · {{ $estimation->fiscal_year }}
                </div>
                <div class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    CHF {{ number_format((float) $estimation->total_tax_burden, 2, '.', "'") }}
                </div>
                <div class="mt-2">
                    <x-filament::badge color="gray" size="sm">
                        {{ number_format((float) $estimation->effective_rate, 1) }}% effective rate
                    </x-filament::badge>
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-arrow-trending-up" class="h-5 w-5 text-gray-400 dark:text-gray-500" />
                    Monthly reserve
                </div>
                <div class="mt-2 text-3xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    CHF {{ number_format((float) $estimation->monthly_reserve, 2, '.', "'") }}
                </div>
                <div class="mt-2 text-sm text-gray-500 dark:text-gray-400">Set aside each month</div>
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
                    of CHF 100,000
                    @if ($estimation->vat_crossing_date)
                        · projected crossing {{ $estimation->vat_crossing_date->format('d.m.Y') }}
                    @endif
                </div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Breakdown" description="How your total tax burden is built up.">
            @php($groups = [
                'Income' => [
                    ['Gross revenue', $estimation->gross_revenue, false],
                    ['Deductible expenses', $estimation->total_expenses, false],
                    ['Net income', $estimation->net_income, true],
                ],
                'Social insurance' => [
                    ['AHV / IV / EO contributions', $estimation->total_social_insurance, false],
                ],
                'Income tax' => [
                    ['Taxable income', $estimation->taxable_income, false],
                    ['Federal tax', $estimation->federal_tax, false],
                    ['Cantonal tax', $estimation->cantonal_tax, false],
                    ['Communal tax', $estimation->communal_tax, false],
                    ['Church tax', $estimation->church_tax, false],
                    ['Total income tax', $estimation->total_income_tax, true],
                ],
            ])

            <table class="w-full text-sm">
                <tbody>
                    @foreach ($groups as $groupLabel => $lines)
                        <tr>
                            <td colspan="2" class="px-4 pt-5 pb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                                {{ $groupLabel }}
                            </td>
                        </tr>
                        @foreach ($lines as [$label, $value, $isSubtotal])
                            <tr @class(['border-t border-gray-100 dark:border-white/5'])>
                                <td @class([
                                    'py-2.5 px-4',
                                    'text-gray-500 dark:text-gray-400' => ! $isSubtotal,
                                    'font-medium text-gray-950 dark:text-white' => $isSubtotal,
                                ])>{{ $label }}</td>
                                <td @class([
                                    'py-2.5 px-4 text-right tabular-nums',
                                    'text-gray-700 dark:text-gray-300' => ! $isSubtotal,
                                    'font-medium text-gray-950 dark:text-white' => $isSubtotal,
                                ])>CHF {{ number_format((float) $value, 2, '.', "'") }}</td>
                            </tr>
                        @endforeach
                    @endforeach
                    <tr class="border-t-2 border-gray-200 dark:border-white/10">
                        <td class="py-3 px-4 text-base font-semibold text-gray-950 dark:text-white">Total tax burden</td>
                        <td class="py-3 px-4 text-right text-base font-semibold tabular-nums text-primary-600 dark:text-primary-400">
                            CHF {{ number_format((float) $estimation->total_tax_burden, 2, '.', "'") }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </x-filament::section>
    @endif

    @php($comparison = $this->getComparison())
    @php($current = $this->currentCantonCode())
    @php($lowestCode = array_key_first($comparison))

    @if (count($comparison))
        <x-filament::section heading="Canton comparison"
            description="What your current figures would cost in other cantons.">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="py-2.5 px-4 text-left font-medium">Canton</th>
                        <th class="py-2.5 px-4 text-right font-medium">Total tax</th>
                        <th class="py-2.5 px-4 text-right font-medium">Effective rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($comparison as $code => $result)
                        <tr @class(['bg-primary-50/60 dark:bg-primary-400/5' => $code === $current])>
                            <td class="py-3 px-4">
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
                                'py-3 px-4 text-right tabular-nums',
                                'font-semibold text-gray-950 dark:text-white' => $code === $current,
                                'text-gray-700 dark:text-gray-300' => $code !== $current,
                            ])>CHF {{ number_format($result->totalTaxBurden, 0, '.', "'") }}</td>
                            <td @class([
                                'py-3 px-4 text-right tabular-nums',
                                'font-semibold text-gray-950 dark:text-white' => $code === $current,
                                'text-gray-500 dark:text-gray-400' => $code !== $current,
                            ])>{{ number_format($result->effectiveRate, 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
