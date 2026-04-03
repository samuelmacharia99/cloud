@props(['method'])

@php
    // Handle both enum instances and string/int values
    if (!$method instanceof \App\Enums\PaymentMethod) {
        $method = \App\Enums\PaymentMethod::tryFrom($method);
    }

    if (!$method) {
        return;
    }

    $colors = [
        'green' => 'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-300 border-green-200 dark:border-green-800',
        'blue' => 'bg-blue-50 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 border-blue-200 dark:border-blue-800',
        'slate' => 'bg-slate-50 dark:bg-slate-900/20 text-slate-700 dark:text-slate-300 border-slate-200 dark:border-slate-800',
        'purple' => 'bg-purple-50 dark:bg-purple-900/20 text-purple-700 dark:text-purple-300 border-purple-200 dark:border-purple-800',
        'amber' => 'bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-300 border-amber-200 dark:border-amber-800',
    ];

    $iconMap = [
        'phone' => 'phone',
        'credit-card' => 'credit-card',
        'building-2' => 'building',
        'wallet' => 'wallet',
        'check' => 'check',
    ];

    $color = $colors[$method->color()] ?? $colors['slate'];
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center gap-2 px-3 py-1.5 rounded-full border text-sm font-medium {$color}"]) }}>
    @include('components.payment-method-icon', ['method' => $method, 'class' => 'w-4 h-4'])
    <span>{{ $method->label() }}</span>
</span>
