{{--
    Tax breakdown table.
    Expects $estimation (App\Models\TaxEstimation), optional $communalEstimated (bool)
    and optional $heading / $description. A business row carries deductions
    scaled to its share (deductions.share), so its income tax lines start from
    its share of the owner's net income and add up to its taxable income.
--}}
<x-filament::section :heading="$heading ?? 'Breakdown'" :description="$description ?? 'How your total tax burden is built up.'">
    @php($deductions = $estimation->rates_snapshot['deductions'] ?? [])
    @php($minimumApplied = (bool) ($deductions['minimum_contribution_applied'] ?? false))
    @php($lossYear = (bool) ($deductions['loss_year'] ?? ((float) $estimation->net_income < 0)))
    @php($ageExemption = (bool) ($deductions['age_exemption_applied'] ?? false))
    @php($isBusinessShare = isset($deductions['share']))
    @php($ahvMinimum = (float) ($estimation->rates_snapshot['ahv_minimum'] ?? 514))
    @php($ageExemptionAmount = (float) ($estimation->rates_snapshot['age_exemption_amount'] ?? 16800))
    @php($groups = [
        'Income' => [
            ['label' => 'Revenue (excl. VAT)', 'value' => $estimation->gross_revenue],
            ['label' => 'Deductible expenses', 'value' => $estimation->total_expenses],
            ['label' => 'Net income', 'value' => $estimation->net_income, 'subtotal' => true],
        ],
        'Social insurance' => [
            ['label' => 'AHV (old-age and survivors’ insurance)', 'value' => $estimation->ahv_contribution],
            ['label' => 'IV (disability insurance)', 'value' => $estimation->iv_contribution],
            ['label' => 'EO (income compensation)', 'value' => $estimation->eo_contribution],
            ['label' => 'Total AHV / IV / EO', 'value' => $estimation->total_social_insurance, 'subtotal' => true, 'minimum' => $minimumApplied],
        ],
        'Income tax' => array_values(array_filter([
            ['label' => $isBusinessShare ? 'Share of your net income' : 'Net income', 'value' => $deductions['net_income'] ?? $estimation->net_income],
            ['label' => '− AHV deduction (50 % of AHV)', 'value' => $deductions['ahv'] ?? $estimation->ahv_deduction],
            ['label' => '− Pillar 3a', 'value' => $deductions['pillar3a'] ?? null],
            ['label' => '− Child deductions', 'value' => $deductions['children'] ?? null],
            ['label' => '+ Other income', 'value' => $deductions['other_income'] ?? null],
            ['label' => '= Taxable income', 'value' => $estimation->taxable_income, 'subtotal' => true],
            ['label' => 'Federal tax', 'value' => $estimation->federal_tax],
            ['label' => 'Cantonal tax', 'value' => $estimation->cantonal_tax],
            ['label' => 'Communal tax', 'value' => $estimation->communal_tax, 'hint' => ($communalEstimated ?? false) ? 'Communal tax rate estimated from the canton average.' : null],
            ['label' => 'Church tax', 'value' => $estimation->church_tax],
            ['label' => 'Total income tax', 'value' => $estimation->total_income_tax, 'subtotal' => true],
        ], fn (array $line): bool => $line['value'] !== null)),
    ])

    <table class="w-full text-sm">
        <tbody>
            @foreach ($groups as $groupLabel => $lines)
                <tr>
                    <td colspan="2" class="px-4 pt-5 pb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        {{ $groupLabel }}
                    </td>
                </tr>
                @foreach ($lines as $line)
                    @php($isSubtotal = $line['subtotal'] ?? false)
                    <tr @class(['border-t border-gray-100 dark:border-white/5'])>
                        <td @class([
                            'py-2.5 px-4',
                            'text-gray-500 dark:text-gray-400' => ! $isSubtotal,
                            'font-medium text-gray-950 dark:text-white' => $isSubtotal,
                        ])>
                            <span class="inline-flex flex-wrap items-center gap-2">
                                {{ $line['label'] }}
                                @if ($line['minimum'] ?? false)
                                    <x-filament::badge color="info" size="sm">Minimum contribution</x-filament::badge>
                                @endif
                            </span>
                            @if (filled($line['hint'] ?? null))
                                <span class="mt-0.5 block text-xs font-normal text-gray-500 dark:text-gray-400">{{ $line['hint'] }}</span>
                            @endif
                            @if ($line['minimum'] ?? false)
                                <span class="mt-0.5 block text-xs font-normal text-gray-500 dark:text-gray-400">
                                    Self-employed people pay at least CHF {{ number_format($ahvMinimum, 0, '.', "'") }} per year
                                </span>
                            @endif
                        </td>
                        <td @class([
                            'py-2.5 px-4 text-right tabular-nums align-top',
                            'text-gray-700 dark:text-gray-300' => ! $isSubtotal,
                            'font-medium text-gray-950 dark:text-white' => $isSubtotal,
                        ])>CHF {{ number_format((float) $line['value'], 2, '.', "'") }}</td>
                    </tr>
                @endforeach
            @endforeach
            <tr class="border-t-2 border-gray-200 dark:border-white/10">
                <td class="py-3 px-4 text-base font-semibold text-gray-950 dark:text-white">Tax owed so far (year to date)</td>
                <td class="py-3 px-4 text-right text-base font-semibold tabular-nums text-primary-600 dark:text-primary-400">
                    CHF {{ number_format((float) $estimation->total_tax_burden, 2, '.', "'") }}
                </td>
            </tr>
            <tr>
                <td colspan="2" class="px-4 pt-5 pb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                    Full year (projected)
                </td>
            </tr>
            <tr class="border-t border-gray-100 dark:border-white/5">
                <td class="py-2.5 px-4 text-gray-500 dark:text-gray-400">
                    Projected revenue
                    <span class="mt-0.5 block text-xs font-normal text-gray-500 dark:text-gray-400">
                        Year to date annualised over {{ $estimation->inputs['days_elapsed'] ?? 0 }} days of trading.
                    </span>
                </td>
                <td class="py-2.5 px-4 text-right tabular-nums align-top text-gray-700 dark:text-gray-300">
                    CHF {{ number_format((float) $estimation->projected_annual_revenue, 2, '.', "'") }}
                </td>
            </tr>
            <tr class="border-t border-gray-100 dark:border-white/5">
                <td class="py-2.5 px-4 font-medium text-gray-950 dark:text-white">Expected tax for the full year</td>
                <td class="py-2.5 px-4 text-right font-medium tabular-nums align-top text-gray-950 dark:text-white">
                    CHF {{ number_format((float) $estimation->projected_total_tax, 2, '.', "'") }}
                </td>
            </tr>
            <tr class="border-t border-gray-100 dark:border-white/5">
                <td class="py-2.5 px-4 font-medium text-gray-950 dark:text-white">
                    Set aside monthly
                    <span class="mt-0.5 block text-xs font-normal text-gray-500 dark:text-gray-400">
                        One twelfth of the full-year estimate, not of the amount owed so far.
                    </span>
                </td>
                <td class="py-2.5 px-4 text-right font-medium tabular-nums align-top text-gray-950 dark:text-white">
                    CHF {{ number_format((float) $estimation->projected_monthly_reserve, 2, '.', "'") }}
                </td>
            </tr>
        </tbody>
    </table>

    @if ($lossYear || $ageExemption)
        <div class="mt-4 space-y-2">
            @if ($lossYear)
                <p class="flex items-start gap-2 text-xs text-warning-700 dark:text-warning-400">
                    <x-filament::icon icon="heroicon-m-arrow-trending-down" class="mt-px h-4 w-4 shrink-0" />
                    <span>
                        Loss year — your expenses exceed your revenue, so no income tax is due.
                        The minimum AHV contribution of CHF {{ number_format($ahvMinimum, 0, '.', "'") }} still applies.
                        Ask your accountant about carrying the loss forward.
                    </span>
                </p>
            @endif
            @if ($ageExemption)
                <p class="flex items-start gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <x-filament::icon icon="heroicon-m-information-circle" class="mt-px h-4 w-4 shrink-0" />
                    <span>
                        AHV age exemption applied — from age 65 the first CHF {{ number_format($ageExemptionAmount, 0, '.', "'") }}
                        of your self-employment income is free of AHV/IV/EO contributions.
                    </span>
                </p>
            @endif
        </div>
    @endif
</x-filament::section>
