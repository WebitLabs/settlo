<x-filament-panels::page>
    @php($rows = $this->getVatRows())
    @php($registered = $this->isRegistered())

    <x-filament::section>
        <x-slot name="heading">
            <span class="inline-flex items-center gap-2">
                {{ $registered ? 'Reclaimable input tax' : 'Input VAT overview' }}
                <span class="text-gray-400 dark:text-gray-500">· {{ $this->fiscalYear() }}</span>
            </span>
        </x-slot>

        <x-slot name="description">
            @if ($registered)
                Your business is VAT-registered, so the input VAT on reviewed expenses can be reclaimed on your MWST return.
            @else
                Your business is not VAT-registered, so this is informational only — you cannot reclaim input VAT until you register.
            @endif
        </x-slot>

        <x-slot name="afterHeader">
            @if ($registered)
                <x-filament::badge color="success" icon="heroicon-m-check-circle">VAT registered</x-filament::badge>
            @else
                <x-filament::badge color="gray" icon="heroicon-m-minus-circle">Not registered</x-filament::badge>
            @endif
        </x-slot>

        @if ($rows === [])
            <div class="mx-auto max-w-md py-10 text-center">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-white/5">
                    <x-filament::icon icon="heroicon-o-receipt-percent" class="h-6 w-6 text-gray-400 dark:text-gray-500" />
                </div>
                <p class="mt-4 text-base font-semibold text-gray-950 dark:text-white">No reviewed expenses yet</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Confirm expenses and their VAT breakdown will appear here.
                </p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        <th class="py-2.5 px-4 text-left font-medium">VAT rate</th>
                        <th class="py-2.5 px-4 text-right font-medium">Gross</th>
                        <th class="py-2.5 px-4 text-right font-medium">Net</th>
                        <th class="py-2.5 px-4 text-right font-medium">{{ $registered ? 'Input VAT (reclaimable)' : 'Input VAT' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="py-3 px-4 font-medium text-gray-950 dark:text-white">{{ $row['rate'] }}%</td>
                            <td class="py-3 px-4 text-right tabular-nums text-gray-700 dark:text-gray-300">CHF {{ number_format((float) $row['gross'], 2, '.', "'") }}</td>
                            <td class="py-3 px-4 text-right tabular-nums text-gray-700 dark:text-gray-300">CHF {{ number_format((float) $row['net'], 2, '.', "'") }}</td>
                            <td class="py-3 px-4 text-right tabular-nums text-gray-700 dark:text-gray-300">CHF {{ number_format((float) $row['input_vat'], 2, '.', "'") }}</td>
                        </tr>
                    @endforeach
                    <tr class="border-t-2 border-gray-200 dark:border-white/10">
                        <td class="py-3 px-4 text-base font-semibold text-gray-950 dark:text-white" colspan="3">
                            {{ $registered ? 'Total reclaimable input tax' : 'Total input VAT paid' }}
                        </td>
                        <td @class([
                            'py-3 px-4 text-right text-base font-semibold tabular-nums',
                            'text-success-600 dark:text-success-400' => $registered,
                            'text-gray-950 dark:text-white' => ! $registered,
                        ])>
                            CHF {{ number_format((float) $this->getTotalInputVat(), 2, '.', "'") }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <p class="mt-4 flex items-start gap-2 text-xs text-gray-500 dark:text-gray-400">
                <x-filament::icon icon="heroicon-m-information-circle" class="mt-px h-4 w-4 shrink-0 text-gray-400 dark:text-gray-500" />
                <span>
                    Input VAT is only reclaimable once you are VAT-registered and file a MWST return. Expenses without a valid VAT breakdown are excluded.
                </span>
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
