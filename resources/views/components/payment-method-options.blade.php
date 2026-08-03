@props([
    'availableGateways' => [],
    'defaultMethod' => null,
    'accent' => 'blue', // blue|emerald|purple
    'methodModel' => 'paymentMethod',
    'phoneModel' => 'mpesaPhone',
    'recommended' => 'mpesa',
    'defaultPhone' => '',
    'mpesaLogo' => '/storage/branding/logo/M-PESA-logo-2.png',
])

@php
    $methods = array_keys($availableGateways);
    $defaultMethod = $defaultMethod ?? ($methods[0] ?? 'mpesa');
    $selectedBorder = match ($accent) {
        'emerald' => 'border-emerald-500 dark:border-emerald-400 bg-emerald-50/60 dark:bg-emerald-950/20',
        'purple' => 'border-purple-500 dark:border-purple-400 bg-purple-50/60 dark:bg-purple-950/20',
        default => 'border-blue-500 dark:border-blue-400 bg-blue-50/60 dark:bg-blue-950/20',
    };
    $radioAccent = match ($accent) {
        'emerald' => 'text-emerald-600 focus:ring-emerald-500',
        'purple' => 'text-purple-600 focus:ring-purple-500',
        default => 'text-blue-600 focus:ring-blue-500',
    };
    $phonePanel = match ($accent) {
        'emerald' => 'bg-emerald-50 dark:bg-emerald-950/20 border-emerald-200 dark:border-emerald-800',
        'purple' => 'bg-purple-50 dark:bg-purple-950/20 border-purple-200 dark:border-purple-800',
        default => 'bg-green-50 dark:bg-green-950/20 border-green-200 dark:border-green-800',
    };
    $hint = [
        'mpesa' => 'We will send an STK push to your phone.',
        'stripe' => 'You will continue on Stripe\'s secure card checkout.',
        'paypal' => 'You will continue on PayPal to approve payment.',
        'manual' => 'Submit your payment reference for admin approval.',
        'bank_transfer' => 'Transfer funds, then submit your bank reference.',
    ];
@endphp

@if (count($availableGateways) === 0)
    <div class="rounded-xl border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4">
        <p class="text-sm text-amber-900 dark:text-amber-200">No online payment methods are currently available. Apply account credit or contact support.</p>
    </div>
@else
    <div class="space-y-3" {{ $attributes }}>
        @foreach ($availableGateways as $method => $gateway)
            <label
                class="relative flex items-start gap-3 sm:gap-4 p-4 rounded-xl border-2 cursor-pointer transition"
                :class="{{ $methodModel }} === '{{ $method }}'
                    ? '{{ $selectedBorder }}'
                    : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-slate-300 dark:hover:border-slate-600'"
            >
                <input
                    type="radio"
                    name="payment_method"
                    value="{{ $method }}"
                    x-model="{{ $methodModel }}"
                    class="mt-1 w-4 h-4 {{ $radioAccent }}"
                    @checked($method === $defaultMethod)
                >

                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 overflow-hidden">
                    @if ($method === 'mpesa')
                        <img src="{{ $mpesaLogo }}" alt="M-PESA" class="h-8 w-8 object-contain">
                    @elseif ($method === 'stripe')
                        <svg class="h-6 w-6" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#635BFF" d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.434 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.876 4.515 3.207 3.718 4.963 3.718 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.515-.921-6.35-2.111l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.591-7.305z"/>
                        </svg>
                    @elseif ($method === 'paypal')
                        <svg class="h-6 w-6" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="#003087" d="M7.076 21.337H2.47a.641.641 0 0 1-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.892 5.028-4.351 6.774-8.636 6.774h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.129zm14.146-14.42a9.16 9.16 0 0 1-.112.961c-1.178 6.08-5.312 8.187-10.536 8.187h-2.19a.9.9 0 0 0-.889.762l-1.313 8.334-.372 2.35a.35.35 0 0 0 .346.403h3.84c.456 0 .844-.333.917-.788l.038-.196.722-4.578.046-.253a.923.923 0 0 1 .917-.788h.578c3.738 0 6.665-1.518 7.521-5.907.357-1.83.172-3.359-.613-4.427z"/>
                            <path fill="#009CDE" d="M21.222 6.917a9.16 9.16 0 0 1-.112.961c-1.178 6.08-5.312 8.187-10.536 8.187h-2.19a.9.9 0 0 0-.889.762l-1.313 8.334-.096.61h4.078a.923.923 0 0 0 .912-.788l.038-.196.722-4.578.046-.253a.923.923 0 0 1 .917-.788h.578c3.738 0 6.665-1.518 7.521-5.907.357-1.83.172-3.359-.613-4.427a4.79 4.79 0 0 0-1.063-.917z"/>
                        </svg>
                    @elseif (in_array($method, ['manual', 'bank_transfer'], true))
                        <svg class="h-6 w-6 text-slate-600 dark:text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 10h18M5 6h14v12a2 2 0 01-2 2H7a2 2 0 01-2-2V6z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 14h.01M12 14h.01M16 14h.01"/>
                        </svg>
                    @else
                        <span class="text-sm font-bold text-slate-700 dark:text-slate-200">{{ strtoupper(substr($gateway['label'] ?? $method, 0, 1)) }}</span>
                    @endif
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white">{{ $gateway['label'] }}</p>
                        @if ($recommended && $method === $recommended && count($availableGateways) > 1)
                            <span class="inline-flex items-center rounded-md bg-emerald-100 dark:bg-emerald-950 text-emerald-800 dark:text-emerald-200 px-1.5 py-0.5 text-[11px] font-semibold">
                                Recommended
                            </span>
                        @endif
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">{{ $gateway['description'] }}</p>
                    @if (! empty($hint[$method]))
                        <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-show="{{ $methodModel }} === '{{ $method }}'" x-cloak>
                            {{ $hint[$method] }}
                        </p>
                    @endif
                </div>
            </label>
        @endforeach

        <div x-show="{{ $methodModel }} === 'mpesa'" x-cloak class="p-4 rounded-xl border {{ $phonePanel }}">
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">M-Pesa phone number</label>
            <input
                type="tel"
                name="phone"
                x-model="{{ $phoneModel }}"
                placeholder="0712345678 or 254712345678"
                :required="{{ $methodModel }} === 'mpesa'"
                class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm focus:ring-2 focus:ring-green-500/40 focus:border-green-500"
            >
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Use the number registered to M-Pesa. Format: 07… or 254…</p>
        </div>
    </div>
@endif
