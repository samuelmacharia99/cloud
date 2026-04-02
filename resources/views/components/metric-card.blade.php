@props(['title', 'value', 'icon', 'color' => 'blue', 'trend' => null, 'href' => null])

@php
$colorMap = [
    'blue' => 'bg-blue-100 dark:bg-blue-950 text-blue-600 dark:text-blue-400',
    'emerald' => 'bg-emerald-100 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400',
    'amber' => 'bg-amber-100 dark:bg-amber-950 text-amber-600 dark:text-amber-400',
    'violet' => 'bg-violet-100 dark:bg-violet-950 text-violet-600 dark:text-violet-400',
    'red' => 'bg-red-100 dark:bg-red-950 text-red-600 dark:text-red-400',
];
@endphp

<div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-all hover:shadow-lg">
    <div class="flex items-start justify-between">
        <div class="flex-1">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">{{ $title }}</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $value }}</p>
            @if($trend)
                <p class="text-xs font-medium mt-2 {{ $trend['positive'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                    {{ $trend['positive'] ? '↑' : '↓' }} {{ $trend['text'] }}
                </p>
            @endif
        </div>
        <div class="w-12 h-12 rounded-xl {{ $colorMap[$color] }} flex items-center justify-center flex-shrink-0">
            {!! $icon !!}
        </div>
    </div>
    @if($href)
        <a href="{{ $href }}" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 mt-4 block">
            View details →
        </a>
    @endif
</div>
