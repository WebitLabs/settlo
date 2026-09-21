<x-filament-panels::page>
    <div
        id="ask-settlo-root"
        data-bootstrap="{{ $this->getBootstrapUrl() }}"
        class="min-h-[32rem]"
    >
        <div class="flex h-96 items-center justify-center text-sm text-gray-500 dark:text-gray-400">
            Loading Ask Settlo…
        </div>
    </div>

    @viteReactRefresh
    @vite('resources/js/ask-settlo-island.jsx')
</x-filament-panels::page>
