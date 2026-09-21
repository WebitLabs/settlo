@php
    $money = fn (string $value): string => 'CHF '.number_format((float) $value, 0, '.', "'");
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        icon="heroicon-o-building-office-2"
        icon-color="primary"
        heading="Your businesses"
        :description="'Year to date ('.$year.'), revenue excl. VAT'"
    >
        @if (count($workspaces) > 0)
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($workspaces as $workspace)
                    <div class="flex flex-col rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-base font-semibold text-gray-950 dark:text-white">{{ $workspace['name'] }}</p>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                    @if ($workspace['type'])
                                        <x-filament::badge color="gray" size="sm">{{ $workspace['type'] }}</x-filament::badge>
                                    @endif
                                    <x-filament::badge :color="$workspace['badge']['color']" size="sm">{{ $workspace['badge']['label'] }}</x-filament::badge>
                                </div>
                            </div>
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                                <x-filament::icon icon="heroicon-m-building-office" class="h-5 w-5" />
                            </span>
                        </div>

                        @if ($workspace['plan'])
                            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $workspace['plan'] }}</p>
                        @endif

                        <dl class="mt-4 grid grid-cols-3 gap-2 text-sm">
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Revenue</dt>
                                <dd class="font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($workspace['revenue']) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Profit</dt>
                                <dd class="font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($workspace['profit']) }}</dd>
                            </div>
                            <div>
                                <dt class="text-xs text-gray-500 dark:text-gray-400">Open</dt>
                                <dd class="font-medium tabular-nums text-gray-950 dark:text-white">{{ $money($workspace['receivables']) }}</dd>
                            </div>
                        </dl>

                        <div class="mt-4 flex flex-wrap gap-2 pt-1">
                            <x-filament::button :href="$workspace['openUrl']" tag="a" size="sm" icon="heroicon-m-arrow-right-circle">
                                Open
                            </x-filament::button>
                            <x-filament::button
                                :href="$workspace['billingUrl']"
                                tag="a"
                                size="sm"
                                :color="$workspace['badge']['needsPayment'] ? 'danger' : 'gray'"
                                :outlined="! $workspace['badge']['needsPayment']"
                                icon="heroicon-m-credit-card"
                            >
                                Billing
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach

                <a href="{{ $setUpUrl }}" class="flex min-h-40 flex-col items-center justify-center rounded-xl border-2 border-dashed border-gray-200 p-4 text-center transition hover:border-primary-400 hover:bg-primary-50/40 dark:border-white/10 dark:hover:border-primary-500/50 dark:hover:bg-primary-400/5">
                    <x-filament::icon icon="heroicon-o-plus-circle" class="h-8 w-8 text-primary-500" />
                    <span class="mt-2 text-sm font-semibold text-gray-950 dark:text-white">Set up another business</span>
                    <span class="mt-1 text-xs text-gray-500 dark:text-gray-400">GmbH &amp; AG — coming soon</span>
                </a>
            </div>
        @else
            <div class="flex flex-col items-center justify-center py-10 text-center">
                <span class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-building-office-2" class="h-8 w-8" />
                </span>
                <p class="mt-3 text-base font-semibold text-gray-950 dark:text-white">No business yet</p>
                <p class="mt-1 max-w-sm text-sm text-gray-500 dark:text-gray-400">
                    Set up your business to start invoicing and tracking your taxes.
                </p>
                <x-filament::button :href="$setUpUrl" tag="a" size="lg" icon="heroicon-m-plus" class="mt-4">
                    Set up your business
                </x-filament::button>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
