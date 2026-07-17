@php
    $dotColors = [
        'red' => 'bg-red-500',
        'amber' => 'bg-amber-400',
        'green' => 'bg-primary-500',
        'info' => 'bg-blue-500',
        'gray' => 'bg-gray-400',
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-center gap-2">
            <x-filament::icon
                icon="heroicon-o-check-circle"
                class="h-5 w-5 text-primary-600 dark:text-primary-400"
            />
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">To-do</h3>
        </div>

        @if (count($items) > 0)
            <ul role="list" class="mt-4 divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($items as $item)
                    <li>
                        <a
                            href="{{ $item['url'] ?? '#' }}"
                            class="flex items-center gap-3 py-2.5 text-sm text-gray-700 transition hover:text-primary-600 dark:text-gray-200 dark:hover:text-primary-400"
                        >
                            <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $dotColors[$item['color']] ?? $dotColors['gray'] }}"></span>
                            <span class="flex-1">{{ $item['label'] }}</span>
                            <x-filament::icon
                                icon="heroicon-m-chevron-right"
                                class="h-4 w-4 text-gray-300 dark:text-gray-600"
                            />
                        </a>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                You're all caught up. Nothing needs your attention right now.
            </p>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
