<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-2">
                <x-filament::icon
                    icon="heroicon-o-chat-bubble-left-right"
                    class="h-5 w-5 text-primary-600 dark:text-primary-400"
                />
                <h3 class="text-base font-semibold text-gray-950 dark:text-white">Ask Settlo</h3>
            </div>

            @if ($chatUrl)
                <a
                    href="{{ $chatUrl }}"
                    class="text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
                >
                    Open chat &rarr;
                </a>
            @endif
        </div>

        @if ($hasConversation)
            <div class="mt-4 space-y-3">
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        You asked
                    </div>
                    <p class="mt-1 text-sm font-medium text-gray-950 dark:text-white">
                        {{ $question ?? $title }}
                    </p>
                </div>

                <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                    <div class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        Settlo AI
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        {{ $answer }}
                    </p>
                </div>
            </div>
        @else
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                Ask your first question about Swiss taxes, AHV, VAT, or deductions and get an instant, context-aware answer.
            </p>
        @endif

        @if ($chatUrl)
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($quickQuestions as $quickQuestion)
                    <a
                        href="{{ $chatUrl }}?q={{ urlencode($quickQuestion) }}"
                        class="inline-flex items-center rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-700 transition hover:border-primary-300 hover:text-primary-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:text-primary-400"
                    >
                        {{ $quickQuestion }}
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
