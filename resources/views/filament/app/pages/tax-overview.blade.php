<x-filament-panels::page>
    @php($estimation = $this->getEstimation())

    @if (! $estimation)
        <x-filament::section>
            <div class="py-10 text-center text-gray-500 dark:text-gray-400">
                <p class="text-lg font-medium">No tax estimate yet</p>
                <p class="mt-1 text-sm">Add revenue and confirmed expenses, then choose <strong>Recalculate</strong>.</p>
            </div>
        </x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-3">
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Total tax burden · {{ $estimation->fiscal_year }}</div>
                <div class="mt-1 text-3xl font-bold text-primary-600">
                    CHF {{ number_format((float) $estimation->total_tax_burden, 2, '.', "'") }}
                </div>
                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Effective rate {{ number_format((float) $estimation->effective_rate, 1) }}%
                </div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">Monthly reserve</div>
                <div class="mt-1 text-3xl font-bold">
                    CHF {{ number_format((float) $estimation->monthly_reserve, 2, '.', "'") }}
                </div>
                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">Set aside each month</div>
            </x-filament::section>

            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">VAT threshold</div>
                <div class="mt-1 text-3xl font-bold">{{ number_format((float) $estimation->vat_threshold_pct, 0) }}%</div>
                <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    of CHF 100,000
                    @if ($estimation->vat_crossing_date)
                        · projected crossing {{ $estimation->vat_crossing_date->format('d.m.Y') }}
                    @endif
                </div>
            </x-filament::section>
        </div>

        <x-filament::section heading="Breakdown">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ([
                        ['Gross revenue', $estimation->gross_revenue],
                        ['Deductible expenses', $estimation->total_expenses],
                        ['Net income', $estimation->net_income],
                        ['AHV / IV / EO contributions', $estimation->total_social_insurance],
                        ['Taxable income', $estimation->taxable_income],
                        ['Federal tax', $estimation->federal_tax],
                        ['Cantonal tax', $estimation->cantonal_tax],
                        ['Communal tax', $estimation->communal_tax],
                        ['Church tax', $estimation->church_tax],
                        ['Total income tax', $estimation->total_income_tax],
                    ] as [$label, $value])
                        <tr>
                            <td class="py-2 text-gray-600 dark:text-gray-400">{{ $label }}</td>
                            <td class="py-2 text-right font-medium">CHF {{ number_format((float) $value, 2, '.', "'") }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-bold">
                        <td class="py-2">Total tax burden</td>
                        <td class="py-2 text-right text-primary-600">
                            CHF {{ number_format((float) $estimation->total_tax_burden, 2, '.', "'") }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </x-filament::section>
    @endif

    @php($comparison = $this->getComparison())
    @php($current = $this->currentCantonCode())

    @if (count($comparison))
        <x-filament::section heading="Canton comparison"
            description="What your current figures would cost in other cantons.">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2">Canton</th>
                        <th class="py-2 text-right">Total tax</th>
                        <th class="py-2 text-right">Effective rate</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($comparison as $code => $result)
                        <tr @class(['font-semibold' => $code === $current])>
                            <td class="py-2">
                                {{ $code }}
                                @if ($code === $current)
                                    <span class="text-xs text-primary-600">(you)</span>
                                @endif
                            </td>
                            <td class="py-2 text-right">CHF {{ number_format($result->totalTaxBurden, 0, '.', "'") }}</td>
                            <td class="py-2 text-right">{{ number_format($result->effectiveRate, 1) }}%</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
