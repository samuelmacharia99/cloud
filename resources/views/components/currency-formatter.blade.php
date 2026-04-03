@props(['amount', 'currency' => 'KES', 'showSymbol' => true, 'decimals' => 2])

@php
    $currencySymbols = [
        'KES' => 'Ksh',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
    ];

    $symbol = $currencySymbols[$currency] ?? $currency;
    $formatted = number_format((float) $amount, $decimals, '.', ',');
@endphp

<span {{ $attributes->merge(['class' => 'font-medium text-slate-900 dark:text-white']) }}>
    @if ($showSymbol)
        <span class="text-sm text-slate-600 dark:text-slate-400">{{ $symbol }}</span>
    @endif
    {{ $formatted }}
</span>
