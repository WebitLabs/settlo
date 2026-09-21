@props(['entity', 'billingUrl'])

<div
    role="alert"
    class="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
>
    <span>
        Your subscription for <strong>{{ $entity->name }}</strong> has ended. Your data is read-only.
    </span>

    <x-filament::button tag="a" :href="$billingUrl" size="sm" color="warning">
        Choose a plan
    </x-filament::button>
</div>
