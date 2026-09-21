@php
    $iconStyles = [
        'red' => 'bg-red-50 text-red-600 dark:bg-red-400/10 dark:text-red-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-400/10 dark:text-amber-400',
        'green' => 'bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400',
        'info' => 'bg-blue-50 text-blue-600 dark:bg-blue-400/10 dark:text-blue-400',
        'gray' => 'bg-gray-100 text-gray-500 dark:bg-white/5 dark:text-gray-400',
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        icon="heroicon-o-check-circle"
        icon-color="primary"
        heading="To-do"
        description="What needs your attention"
    >
        @if (count($items) > 0)
            <ul role="list" class="-mx-2 divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($items as $item)
                    <li>
                        <a
                            href="{{ $item['url'] ?? '#' }}"
                            class="group flex items-center gap-3 rounded-lg px-2 py-3 transition hover:bg-gray-50 dark:hover:bg-white/5"
                        >
                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg {{ $iconStyles[$item['color']] ?? $iconStyles['gray'] }}">
                                <x-filament::icon
                                    :icon="$item['icon'] ?? 'heroicon-m-bell'"
                                    class="h-5 w-5"
                                />
                            </span>
                            <span class="flex-1 text-sm font-medium text-gray-800 dark:text-gray-100">
                                {{ $item['label'] }}
                            </span>
                            <x-filament::icon
                                icon="heroicon-m-chevron-right"
                                class="h-4 w-4 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-gray-400 dark:text-gray-600 dark:group-hover:text-gray-500"
                            />
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <div class="flex flex-col items-center justify-center py-8 text-center">
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-7 w-7" />
                </span>
                <p class="mt-3 text-sm font-semibold text-gray-950 dark:text-white">All caught up</p>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Nothing needs your attention right now.
                </p>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
