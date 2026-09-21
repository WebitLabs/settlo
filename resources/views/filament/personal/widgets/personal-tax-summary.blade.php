@php
    $money = fn (float|string|null $value): string => 'CHF '.number_format((float) $value, 0, '.', "'");
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        icon="heroicon-o-calculator"
        icon-color="primary"
        heading="Personal tax"
        :description="$estimation ? 'Consolidated across '.$businessCount.' '.\Illuminate\Support\Str::plural('business', $businessCount) : 'Income tax and AHV are levied on you, not on each business'"
    >
        @if (! $hasTaxEngine)
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Your personal tax estimate is included in the Pro plan.
            </p>
            <x-filament::button :href="$billingUrl" tag="a" size="sm" color="gray" class="mt-3" icon="heroicon-m-sparkles">
                See plans
            </x-filament::button>
        @elseif ($estimation)
            @if ($pendingCount > 0)
                <p class="mb-3 flex items-start gap-1.5 text-xs text-warning-700 dark:text-warning-400">
                    <x-filament::icon icon="heroicon-m-clock" class="mt-px h-4 w-4 shrink-0" />
                    <span>{{ trans_choice(':count expense awaiting confirmation is|:count expenses awaiting confirmation are', $pendingCount, ['count' => $pendingCount]) }} not included.</span>
                </p>
            @endif

            <dl class="divide-y divide-gray-100 dark:divide-white/10">
                <div class="flex items-center justify-between py-2.5">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Tax owed so far</dt>
                    <dd class="text-base font-semibold tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->total_tax_burden) }}</dd>
                </div>
                <div class="flex items-center justify-between py-2.5">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Expected for the full year</dt>
                    <dd class="text-sm font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->projected_total_tax) }}</dd>
                </div>
                <div class="flex items-center justify-between py-2.5">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Set aside monthly</dt>
                    <dd class="text-sm font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->projected_monthly_reserve) }}</dd>
                </div>
                <div class="flex items-center justify-between py-2.5">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Effective rate</dt>
                    <dd class="text-sm font-medium tabular-nums text-gray-950 dark:text-white">{{ number_format((float) $estimation->effective_rate, 1) }}%</dd>
                </div>
                <div class="flex items-center justify-between py-2.5">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">AHV / IV / EO</dt>
                    <dd class="text-sm font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($estimation->total_social_insurance) }}</dd>
                </div>
            </dl>

            <x-filament::link :href="$personalTaxUrl" icon="heroicon-m-arrow-right" icon-position="after" class="mt-3">
                View personal tax
            </x-filament::link>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Complete your tax profile to see your estimated income tax, AHV and monthly reserve.
            </p>
            <x-filament::button :href="$taxProfileUrl" tag="a" size="sm" class="mt-3" icon="heroicon-m-arrow-right" icon-position="after">
                Complete your tax profile
            </x-filament::button>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
