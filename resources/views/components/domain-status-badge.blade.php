@props(['status'])

@php
    $styles = match(strtolower($status)) {
        'active' => ['pill' => 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300', 'dot' => 'bg-emerald-500'],
        'pending' => ['pill' => 'bg-amber-100 dark:bg-amber-950/60 text-amber-700 dark:text-amber-300', 'dot' => 'bg-amber-500 animate-pulse'],
        'expired' => ['pill' => 'bg-red-100 dark:bg-red-950/60 text-red-700 dark:text-red-300', 'dot' => 'bg-red-500'],
        'suspended' => ['pill' => 'bg-orange-100 dark:bg-orange-950/60 text-orange-700 dark:text-orange-300', 'dot' => 'bg-orange-500'],
        default => ['pill' => 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300', 'dot' => 'bg-slate-400'],
    };
@endphp

<span {{ $attributes->merge(['class' => "status-pill {$styles['pill']}"]) }}>
    <span class="status-pill-dot {{ $styles['dot'] }}"></span>
    {{ ucfirst($status) }}
</span>
