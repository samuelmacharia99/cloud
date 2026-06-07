@props(['title', 'value', 'icon', 'color' => 'blue', 'trend' => null, 'href' => null, 'subtitle' => null])

@php
$colorMap = [
    'blue' => 'bg-brand-100 dark:bg-brand-950/60 text-brand-600 dark:text-brand-400 ring-brand-200/50 dark:ring-brand-800/50',
    'emerald' => 'bg-emerald-100 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400 ring-emerald-200/50 dark:ring-emerald-800/50',
    'amber' => 'bg-amber-100 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400 ring-amber-200/50 dark:ring-amber-800/50',
    'violet' => 'bg-violet-100 dark:bg-violet-950/60 text-violet-600 dark:text-violet-400 ring-violet-200/50 dark:ring-violet-800/50',
    'red' => 'bg-red-100 dark:bg-red-950/60 text-red-600 dark:text-red-400 ring-red-200/50 dark:ring-red-800/50',
];

$icon = preg_replace('/<script[\s\S]*?<\/script>/i', '', $icon ?? '');
@endphp

<div class="ui-card ui-card-interactive p-5 sm:p-6 group">
    <div class="flex items-start justify-between gap-4">
        <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $title }}</p>
            <p class="text-2xl sm:text-3xl font-bold tracking-tight text-slate-900 dark:text-white mt-1.5 truncate">{{ $value }}</p>
            @if($subtitle)
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-2">{{ $subtitle }}</p>
            @endif
            @if($trend)
                <p class="text-xs font-semibold mt-2 inline-flex items-center gap-1 {{ $trend['positive'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                    <span>{{ $trend['positive'] ? '↑' : '↓' }}</span>
                    {{ $trend['text'] }}
                </p>
            @endif
        </div>
        <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl ring-1 {{ $colorMap[$color] }} flex items-center justify-center flex-shrink-0 transition-transform group-hover:scale-105">
            {!! $icon !!}
        </div>
    </div>
    @if($href)
        <a href="{{ $href }}" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-600 dark:text-brand-400 hover:text-brand-700 dark:hover:text-brand-300 mt-4 transition-colors">
            View details
            <svg class="w-4 h-4 transition-transform group-hover:translate-x-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    @endif
</div>
