@php
    /** @var array<int, string> $steps */
    /** @var int $current */
    $total = count($steps);
@endphp

<nav aria-label="Set-up progress" class="flex flex-col gap-3">
    <div class="flex items-center justify-end">
        <span class="text-sm text-gray-500 dark:text-gray-400">Step {{ $current }} of {{ $total }}</span>
    </div>

    <ol class="flex items-center">
        @foreach ($steps as $number => $label)
            @php
                $isComplete = $number < $current;
                $isCurrent = $number === $current;
            @endphp

            <li
                @class([
                    'flex items-center',
                    'flex-1' => ! $loop->last,
                ])
                data-step="{{ $number }}" data-state="{{ $isComplete ? 'complete' : ($isCurrent ? 'current' : 'upcoming') }}"
                @if ($isCurrent) aria-current="step" @endif
            >
                <div class="flex items-center gap-3">
                    @if ($isComplete)
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-white dark:bg-primary-500">
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::Check" class="size-5" />
                            <span class="sr-only">Completed:</span>
                        </span>
                    @elseif ($isCurrent)
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-primary-600 text-sm font-semibold text-primary-600 dark:border-primary-400 dark:text-primary-400">
                            {{ $number }}
                        </span>
                    @else
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-gray-300 text-sm font-medium text-gray-500 dark:border-white/20 dark:text-gray-400">
                            {{ $number }}
                        </span>
                    @endif

                    <span
                        @class([
                            'hidden text-sm sm:inline',
                            'font-semibold text-gray-950 dark:text-white' => $isCurrent,
                            'font-medium text-gray-700 dark:text-gray-200' => $isComplete,
                            'text-gray-500 dark:text-gray-400' => ! $isCurrent && ! $isComplete,
                        ])
                    >{{ $label }}</span>
                </div>

                @unless ($loop->last)
                    <span
                        aria-hidden="true"
                        @class([
                            'mx-3 h-0.5 flex-1 rounded-full sm:mx-4',
                            'bg-primary-600 dark:bg-primary-500' => $isComplete,
                            'bg-gray-200 dark:bg-white/10' => ! $isComplete,
                        ])
                    ></span>
                @endunless
            </li>
        @endforeach
    </ol>
</nav>
