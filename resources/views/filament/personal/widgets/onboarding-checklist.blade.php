<x-filament-widgets::widget>
    <x-filament::section
        icon="heroicon-o-rocket-launch"
        icon-color="primary"
        :heading="'Get started — '.$done.' of '.$total"
        description="A few steps to get the most out of Settlo"
    >
        <div class="mb-4 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-primary-500 transition-all" style="width: {{ $percent }}%"></div>
        </div>

        <ul role="list" class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($items as $item)
                <li>
                    @if (! $item['done'] && $item['url'])
                        <a href="{{ $item['url'] }}" class="group flex items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 transition hover:border-primary-300 hover:bg-primary-50/50 dark:border-white/10 dark:hover:border-primary-500/40 dark:hover:bg-primary-400/5">
                            <x-filament::icon icon="heroicon-o-stop" class="h-5 w-5 shrink-0 text-gray-400 dark:text-gray-500" />
                            <span class="flex-1 text-sm font-medium text-gray-800 dark:text-gray-100">{{ $item['label'] }}</span>
                            <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-primary-500 dark:text-gray-600" />
                        </a>
                    @else
                        <div @class([
                            'flex items-center gap-3 rounded-lg border px-3 py-2.5',
                            'border-gray-100 bg-gray-50 dark:border-white/5 dark:bg-white/5' => $item['done'],
                            'border-gray-200 dark:border-white/10' => ! $item['done'],
                        ])>
                            <x-filament::icon
                                :icon="$item['done'] ? 'heroicon-s-check-circle' : 'heroicon-o-stop'"
                                @class([
                                    'h-5 w-5 shrink-0',
                                    'text-primary-500' => $item['done'],
                                    'text-gray-400 dark:text-gray-500' => ! $item['done'],
                                ])
                            />
                            <span @class([
                                'flex-1 text-sm',
                                'text-gray-500 line-through dark:text-gray-400' => $item['done'],
                                'font-medium text-gray-800 dark:text-gray-100' => ! $item['done'],
                            ])>{{ $item['label'] }}</span>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
