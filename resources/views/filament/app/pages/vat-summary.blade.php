<x-filament-panels::page>
    @php($rows = $this->getVatRows())
    @php($registered = $this->isRegistered())

    <x-filament::section>
        @if ($registered)
            <x-slot name="heading">Reclaimable input tax · {{ $this->fiscalYear() }}</x-slot>
            <x-slot name="description">
                Your business is VAT-registered, so the input VAT on reviewed expenses can be reclaimed on your MWST return.
            </x-slot>
        @else
            <x-slot name="heading">Input VAT overview · {{ $this->fiscalYear() }}</x-slot>
            <x-slot name="description">
                Your business is not VAT-registered, so this is informational only — you cannot reclaim input VAT until you register.
            </x-slot>
        @endif

        @if ($rows === [])
            <div class="py-10 text-center text-gray-500 dark:text-gray-400">
                <p class="text-lg font-medium">No reviewed expenses yet</p>
                <p class="mt-1 text-sm">Confirm expenses and their VAT breakdown will appear here.</p>
            </div>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 dark:text-gray-400">
                        <th class="py-2">VAT rate</th>
                        <th class="py-2 text-right">Gross</th>
                        <th class="py-2 text-right">Net</th>
                        <th class="py-2 text-right">{{ $registered ? 'Input VAT (reclaimable)' : 'Input VAT' }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="py-2 font-medium">{{ $row['rate'] }}%</td>
                            <td class="py-2 text-right">CHF {{ number_format((float) $row['gross'], 2, '.', "'") }}</td>
                            <td class="py-2 text-right">CHF {{ number_format((float) $row['net'], 2, '.', "'") }}</td>
                            <td class="py-2 text-right">CHF {{ number_format((float) $row['input_vat'], 2, '.', "'") }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-bold">
                        <td class="py-2" colspan="3">{{ $registered ? 'Total reclaimable input tax' : 'Total input VAT paid' }}</td>
                        <td class="py-2 text-right text-primary-600">
                            CHF {{ number_format((float) $this->getTotalInputVat(), 2, '.', "'") }}
                        </td>
                    </tr>
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
