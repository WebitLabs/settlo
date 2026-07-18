@php
    $money = fn (float|string|null $value): string => "CHF ".number_format((float) $value, 0, '.', "'");
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        icon="heroicon-o-calculator"
        icon-color="primary"
        heading="Tax estimate"
        description="Your latest projected liabilities"
    >
        @if ($estimation)
            <dl class="divide-y divide-gray-100 dark:divide-white/10">
                <div class="flex items-center justify-between py-2.5">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Income tax</dt>
                    <dd class="text-sm font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->total_income_tax) }}</dd>
                </div>
                <div class="flex items-center justify-between py-2.5">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">AHV / IV / EO</dt>
                    <dd class="text-sm font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->total_social_insurance) }}</dd>
                </div>
                <div class="flex items-center justify-between py-2.5">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">VAT status</dt>
                    <dd>
                        <x-filament::badge :color="$vatColor">
                            {{ $vatLabel }}
                        </x-filament::badge>
                    </dd>
                </div>
                <div class="flex items-center justify-between pt-3">
                    <dt class="text-sm font-semibold text-gray-950 dark:text-white">Total tax burden</dt>
                    <dd class="text-base font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->total_tax_burden) }}</dd>
                </div>
            </dl>

            <div class="mt-4 rounded-xl bg-gray-900 p-4 text-white ring-1 ring-inset ring-white/10 dark:bg-white/5">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-400">Monthly reserve</div>
                <div class="mt-1 text-2xl font-semibold tabular-nums tracking-tight">{{ $money($estimation->monthly_reserve) }}<span class="text-sm font-normal text-gray-400"> / month</span></div>
                <div class="mt-1 text-xs text-gray-400">Updated {{ $estimation->calculated_at->diffForHumans() }}</div>
            </div>
        @else
            <div class="flex flex-col items-center justify-center py-8 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-calculator" class="h-7 w-7" />
                </span>
                <p class="mt-3 text-sm font-semibold text-gray-950 dark:text-white">No estimate yet</p>
                <p class="mt-1 max-w-xs text-sm text-gray-500 dark:text-gray-400">
                    Complete your tax profile to see your estimated income tax, AHV, and monthly reserve.
                </p>
                @if ($settingsUrl)
                    <x-filament::button
                        :href="$settingsUrl"
                        tag="a"
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                        class="mt-4"
                    >
                        Complete your tax profile
                    </x-filament::button>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
