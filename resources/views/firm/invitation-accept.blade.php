<!doctype html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accept invitation · Settlo</title>
    @vite('resources/css/app.css')
</head>
<body class="min-h-full bg-gray-50 text-gray-950 antialiased dark:bg-gray-950 dark:text-white">
    <div class="flex min-h-screen items-center justify-center p-4 sm:p-6">
        <div class="w-full max-w-lg rounded-2xl bg-white p-8 shadow-xl ring-1 ring-gray-950/5 sm:p-10 dark:bg-gray-900 dark:ring-white/10">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-[#0F6E56] text-sm font-bold text-white">S</span>
                <span class="text-sm font-bold uppercase tracking-[0.2em] text-[#0F6E56] dark:text-emerald-400">Settlo</span>
            </div>

            <div class="mt-8">
                <p class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Firm invitation</p>
                <h1 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                    {{ $firmName }}
                </h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    wants to manage your books.
                </p>
            </div>

            <p class="mt-5 text-sm leading-relaxed text-gray-600 dark:text-gray-400">
                Choose which business to grant access to. The firm will be able to review that
                business's invoices, expenses and tax estimates. You can revoke access at any time.
            </p>

            @if ($entities->isEmpty())
                <div class="mt-6 rounded-xl bg-gray-50 p-4 text-sm text-gray-600 ring-1 ring-gray-950/5 dark:bg-white/5 dark:text-gray-400 dark:ring-white/10">
                    You do not have any businesses to assign yet. Finish setting up a business first,
                    then re-open this invitation link.
                </div>
            @else
                <form method="POST" action="{{ route('firm-invitations.store', ['token' => $token]) }}" class="mt-6">
                    @csrf
                    <label for="business_entity_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Business to assign
                    </label>
                    <select
                        id="business_entity_id"
                        name="business_entity_id"
                        required
                        class="mt-2 block w-full rounded-lg border-0 bg-white py-2.5 pl-3 pr-10 text-sm text-gray-950 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-[#0F6E56] dark:bg-white/5 dark:text-white dark:ring-white/10 dark:focus:ring-emerald-400"
                    >
                        @foreach ($entities as $entity)
                            <option value="{{ $entity->getKey() }}">{{ $entity->name }}</option>
                        @endforeach
                    </select>
                    @error('business_entity_id')
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                    @enderror

                    <button
                        type="submit"
                        class="mt-6 flex w-full items-center justify-center rounded-lg bg-[#0F6E56] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#0c5946] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0F6E56] focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900"
                    >
                        Grant access to {{ $firmName }}
                    </button>
                </form>

                <a href="/app" class="mt-4 block text-center text-sm font-medium text-gray-500 transition hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                    Not now
                </a>
            @endif

            <div class="mt-8 border-t border-gray-950/5 pt-5 text-xs text-gray-400 dark:border-white/10 dark:text-gray-500">
                Invited as {{ $invitation->email }}
            </div>
        </div>
    </div>
</body>
</html>
