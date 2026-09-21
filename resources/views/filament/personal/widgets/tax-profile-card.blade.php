<x-filament-widgets::widget>
    <x-filament::section
        icon="heroicon-o-identification"
        icon-color="primary"
        heading="Tax profile"
        description="Applies to all of your businesses"
    >
        @if (count($rows) > 0)
            <dl class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($rows as $label => $value)
                    <div class="flex items-start justify-between gap-3 py-2.5">
                        <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="text-right text-sm font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <x-filament::link :href="$editUrl" icon="heroicon-m-pencil-square" class="mt-3">
                Edit tax profile
            </x-filament::link>
        @else
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Tell us where you live and about your household so we can estimate your taxes.
            </p>
            <x-filament::button :href="$editUrl" tag="a" size="sm" class="mt-3" icon="heroicon-m-arrow-right" icon-position="after">
                Complete your tax profile
            </x-filament::button>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
