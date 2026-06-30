@props([
    'label',
    'value',
    'href' => null,
    'subtitle' => null,
    'tone' => 'purple',
])

@php
    $toneClasses = match ($tone) {
        'emerald' => 'hover:border-emerald-300 dark:hover:border-emerald-700 bg-emerald-100 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400',
        'amber' => 'hover:border-amber-300 dark:hover:border-amber-700 bg-amber-100 dark:bg-amber-950 text-amber-600 dark:text-amber-400',
        'blue' => 'hover:border-blue-300 dark:hover:border-blue-700 bg-blue-100 dark:bg-blue-950 text-blue-600 dark:text-blue-400',
        default => 'hover:border-purple-300 dark:hover:border-purple-700 bg-purple-100 dark:bg-purple-950 text-purple-600 dark:text-purple-400',
    };
    $valueClasses = match ($tone) {
        'emerald' => 'text-emerald-600',
        'amber' => 'text-amber-600',
        default => 'text-slate-900 dark:text-white',
    };
@endphp

@if ($href)
    <a href="{{ $href }}" class="block bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 sm:p-6 transition-all shadow-sm hover:shadow-md {{ str_contains($toneClasses, 'hover:border') ? $toneClasses : 'hover:border-purple-300 dark:hover:border-purple-700' }}">
@else
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200/80 dark:border-slate-800 p-5 sm:p-6 shadow-sm">
@endif
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $label }}</p>
            <p class="text-2xl sm:text-3xl font-bold {{ $valueClasses }} mt-1 truncate">{{ $value }}</p>
            @if ($subtitle)
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-2 leading-relaxed">{{ $subtitle }}</p>
            @endif
        </div>
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 {{ explode(' ', $toneClasses)[2] ?? 'bg-purple-100' }} {{ explode(' ', $toneClasses)[4] ?? 'text-purple-600' }}">
            {{ $icon ?? '' }}
        </div>
    </div>
@if ($href)
    </a>
@else
    </div>
@endif
