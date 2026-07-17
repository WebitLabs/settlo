@php
    $labels = [
        'mandatory' => 'Registration required',
        'critical' => 'Critical',
        'warning' => 'Warning',
        'info' => 'On track',
        'none' => 'Well below threshold',
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <x-filament::icon
                    icon="heroicon-o-receipt-percent"
                    class="h-5 w-5 text-primary-600 dark:text-primary-400"
                />
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">VAT threshold</h3>
            </div>
            <span class="text-sm font-semibold text-gray-950 dark:text-white">
                {{ number_format($progressPct, 1) }}%
            </span>
        </div>

        <div class="mt-4">
            <div class="h-2.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                <div
                    class="h-full rounded-full transition-all {{ $barColor }}"
                    style="width: {{ $barPct }}%"
                ></div>
            </div>
            <div class="mt-2 flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>CHF 0</span>
                <span>CHF 100'000</span>
            </div>
        </div>

        <div class="mt-4 space-y-1 text-sm">
            <p class="font-medium text-gray-950 dark:text-white">
                {{ $labels[$level] ?? $labels['none'] }}
            </p>
            @if ($hasData && $crossingDate)
                <p class="text-gray-500 dark:text-gray-400">
                    Projected to cross the threshold around <span class="font-medium text-gray-950 dark:text-white">{{ $crossingDate }}</span>.
                </p>
            @elseif (! $hasData)
                <p class="text-gray-500 dark:text-gray-400">
                    Send your first invoice to start tracking progress toward the VAT registration threshold.
                </p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
