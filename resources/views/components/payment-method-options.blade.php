@props([
    'availableGateways' => [],
    'defaultMethod' => null,
    'accent' => 'blue',
])

@php
    $methods = array_keys($availableGateways);
    $defaultMethod = $defaultMethod ?? ($methods[0] ?? 'mpesa');
    $borderAccent = match ($accent) {
        'emerald' => 'hover:border-emerald-500 dark:hover:border-emerald-400',
        default => 'hover:border-blue-500 dark:hover:border-blue-400',
    };
    $radioAccent = match ($accent) {
        'emerald' => 'text-emerald-600',
        default => 'text-blue-600',
    };
    $phonePanelAccent = match ($accent) {
        'emerald' => 'bg-emerald-50 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-800',
        default => 'bg-blue-50 dark:bg-blue-950/20 border-blue-200 dark:border-blue-800',
    };
@endphp

@if (count($availableGateways) === 0)
    <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4">
        <p class="text-sm text-amber-900 dark:text-amber-200">No online payment methods are currently available. Please contact support.</p>
    </div>
@else
    <div class="space-y-2">
        @foreach ($availableGateways as $method => $gateway)
            <label class="flex items-center p-3 sm:p-4 border-2 border-slate-200 dark:border-slate-700 rounded-lg {{ $borderAccent }} cursor-pointer transition" @click="paymentMethod = '{{ $method }}'">
                <input
                    type="radio"
                    name="payment_method"
                    value="{{ $method }}"
                    x-model="paymentMethod"
                    class="w-4 h-4 {{ $radioAccent }}"
                    @checked($method === $defaultMethod)
                >
                <div class="ml-3 sm:ml-4 flex-1">
                    <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $gateway['label'] }}</p>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">{{ $gateway['description'] }}</p>
                </div>
            </label>
        @endforeach
    </div>

    <div x-show="paymentMethod === 'mpesa'" x-cloak class="mt-4 p-4 rounded-lg border {{ $phonePanelAccent }}">
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">M-Pesa Phone Number</label>
        <input
            type="tel"
            name="phone"
            placeholder="+254712345678"
            :required="paymentMethod === 'mpesa'"
            class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white"
        >
        <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Enter your M-Pesa registered phone number (with country code)</p>
    </div>
@endif
