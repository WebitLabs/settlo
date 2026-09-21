@php
    use App\Support\Money;
    use App\Support\SimulatedBilling;
@endphp

@if ($payments->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">
        No payments yet. Your first charge appears here once a business is on a paid plan.
    </p>
@else
    <ul role="list" class="divide-y divide-gray-100 dark:divide-white/5">
        @foreach ($payments as $payment)
            @php
                $simulated = SimulatedBilling::isSimulatedPayment($payment->gateway);
            @endphp
            <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                        {{ $payment->subscription?->businessEntity?->name ?? 'Business' }}
                        <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $payment->plan?->name ?? 'Plan' }}</span>
                    </p>
                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                        {{ $payment->paid_at?->translatedFormat('j M Y') ?? 'Not settled' }}
                        @if ($payment->period_start && $payment->period_end)
                            · {{ $payment->period_start->translatedFormat('j M Y') }} – {{ $payment->period_end->translatedFormat('j M Y') }}
                        @endif
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    @if ($simulated)
                        <x-filament::badge color="warning" size="sm">Simulated</x-filament::badge>
                    @endif
                    <x-filament::badge :color="$payment->status === 'paid' ? 'success' : 'gray'" size="sm">
                        {{ ucfirst($payment->status) }}
                    </x-filament::badge>
                    <span class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                        {{ Money::format($payment->amount, $payment->currency_code) }}
                    </span>
                </div>
            </li>
        @endforeach
    </ul>
@endif
