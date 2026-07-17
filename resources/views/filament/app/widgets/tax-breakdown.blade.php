@php
    $money = fn (float|string|null $value): string => "CHF ".number_format((float) $value, 0, '.', "'");
    $badgeColors = [
        'success' => 'bg-primary-50 text-primary-700 dark:bg-primary-400/10 dark:text-primary-400',
        'warning' => 'bg-amber-50 text-amber-700 dark:bg-amber-400/10 dark:text-amber-400',
        'danger' => 'bg-red-50 text-red-700 dark:bg-red-400/10 dark:text-red-400',
        'gray' => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300',
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center gap-2">
            <x-filament::icon
                icon="heroicon-o-calculator"
                class="h-5 w-5 text-primary-600 dark:text-primary-400"
            />
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">Tax estimate</h3>
        </div>

        @if ($estimation)
            <dl class="mt-4 space-y-3">
                <div class="flex items-center justify-between">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">Income tax</dt>
                    <dd class="text-sm font-medium text-gray-950 dark:text-white">{{ $money($estimation->total_income_tax) }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">AHV / IV / EO</dt>
                    <dd class="text-sm font-medium text-gray-950 dark:text-white">{{ $money($estimation->total_social_insurance) }}</dd>
                </div>
                <div class="flex items-center justify-between">
                    <dt class="text-sm text-gray-500 dark:text-gray-400">VAT status</dt>
                    <dd>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $badgeColors[$vatColor] ?? $badgeColors['gray'] }}">
                            {{ $vatLabel }}
                        </span>
                    </dd>
                </div>

                <div class="flex items-center justify-between border-t border-gray-100 pt-3 dark:border-white/10">
                    <dt class="text-sm font-semibold text-gray-950 dark:text-white">Total tax burden</dt>
                    <dd class="text-sm font-semibold text-gray-950 dark:text-white">{{ $money($estimation->total_tax_burden) }}</dd>
                </div>
            </dl>

            <div class="mt-4 rounded-lg bg-gray-900 p-4 text-white dark:bg-white/5">
                <div class="text-xs font-medium uppercase tracking-wide text-gray-400">Monthly reserve</div>
                <div class="mt-1 text-2xl font-semibold">{{ $money($estimation->monthly_reserve) }}<span class="text-sm font-normal text-gray-400"> / month</span></div>
                <div class="mt-1 text-xs text-gray-400">Updated {{ $estimation->calculated_at->diffForHumans() }}</div>
            </div>
        @else
            <div class="mt-4 rounded-lg border border-dashed border-gray-200 p-6 text-center dark:border-white/10">
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Complete your tax profile to see your estimated income tax, AHV, and monthly reserve.
                </p>
                @if ($settingsUrl)
                    <a
                        href="{{ $settingsUrl }}"
                        class="mt-3 inline-flex items-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-primary-500"
                    >
                        Complete your tax profile
                    </a>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
