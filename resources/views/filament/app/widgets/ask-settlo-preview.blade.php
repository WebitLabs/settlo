<x-filament-widgets::widget>
    <x-filament::section
        icon="heroicon-o-chat-bubble-left-right"
        icon-color="primary"
        heading="Ask Settlo"
        description="Instant answers on Swiss taxes, AHV, VAT & deductions"
    >
        @if ($chatUrl)
            <x-slot name="afterHeader">
                <x-filament::link
                    :href="$chatUrl"
                    icon="heroicon-m-arrow-right"
                    icon-position="after"
                    size="sm"
                >
                    Open chat
                </x-filament::link>
            </x-slot>
        @endif

        @if ($hasConversation)
            <div class="space-y-3">
                <div class="flex justify-end">
                    <div class="max-w-[85%] rounded-2xl rounded-br-sm bg-primary-600 px-4 py-2.5 text-sm font-medium text-white shadow-sm">
                        {{ $question ?? $title }}
                    </div>
                </div>

                <div class="flex items-start gap-2.5">
                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                        <x-filament::icon icon="heroicon-m-sparkles" class="h-4 w-4" />
                    </span>
                    <div class="max-w-[85%] rounded-2xl rounded-tl-sm bg-gray-100 px-4 py-2.5 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-200">
                        {{ $answer }}
                    </div>
                </div>
            </div>
        @else
            <div class="flex items-start gap-2.5">
                <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-400/10 dark:text-primary-400">
                    <x-filament::icon icon="heroicon-m-sparkles" class="h-4 w-4" />
                </span>
                <div class="max-w-[85%] rounded-2xl rounded-tl-sm bg-gray-100 px-4 py-2.5 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
                    Ask your first question about Swiss taxes, AHV, VAT, or deductions and get an instant, context-aware answer.
                </div>
            </div>
        @endif

        @if ($chatUrl)
            <div class="mt-5 flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4 dark:border-white/10">
                <span class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">Try asking</span>
                @foreach ($quickQuestions as $quickQuestion)
                    <x-filament::button
                        tag="a"
                        :href="$chatUrl.'?q='.urlencode($quickQuestion)"
                        color="gray"
                        size="xs"
                        icon="heroicon-m-plus"
                    >
                        {{ $quickQuestion }}
                    </x-filament::button>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
