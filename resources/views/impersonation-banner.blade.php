@php
    /** @var \App\Models\User|null $impersonatedUser */
    $impersonatedUser = auth()->user();
@endphp

<div
    role="alert"
    style="position: fixed; top: 0; left: 0; right: 0; z-index: 9999; display: flex; align-items: center; justify-content: center; gap: 0.75rem; padding: 0.5rem 1rem; background-color: #F59E0B; color: #1F1300; font-size: 0.875rem; font-weight: 600; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15);"
>
    <span>
        Impersonating {{ $impersonatedUser?->getFilamentName() ?? 'user' }}
    </span>

    <form method="POST" action="{{ route('impersonation.stop') }}" style="margin: 0;">
        @csrf
        <button
            type="submit"
            style="cursor: pointer; padding: 0.15rem 0.75rem; border-radius: 0.375rem; background-color: #1F1300; color: #F59E0B; font-weight: 700; border: none;"
        >
            Stop impersonating
        </button>
    </form>
</div>
