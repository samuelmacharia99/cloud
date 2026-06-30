@props([
    'onboarding' => [],
    'hasDirectAdmin' => false,
])

@php
    $steps = $onboarding['steps'] ?? [];
    $completed = (int) ($onboarding['completed'] ?? 0);
    $total = (int) ($onboarding['total'] ?? 0);
    $isComplete = (bool) ($onboarding['is_complete'] ?? false);
@endphp

@if (! $isComplete && $total > 0)
<div class="rounded-2xl border border-amber-200 dark:border-amber-800 bg-amber-50/80 dark:bg-amber-950/30 p-5 sm:p-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div>
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">Getting started</p>
            <p class="text-xs text-amber-800 dark:text-amber-200 mt-0.5">{{ $completed }} of {{ $total }} setup steps complete</p>
        </div>
        <div class="w-full sm:w-40 h-2 bg-amber-200 dark:bg-amber-900 rounded-full overflow-hidden">
            <div class="h-full bg-amber-500 rounded-full transition-all" style="width: {{ $total > 0 ? round(($completed / $total) * 100) : 0 }}%"></div>
        </div>
    </div>
    <ul class="space-y-2">
        @foreach ($steps as $step)
            @if (($step['key'] ?? '') !== 'link_accounts' || $hasDirectAdmin)
                <li>
                    <a href="{{ $step['url'] ?? '#' }}" class="flex items-center gap-3 p-2.5 rounded-lg hover:bg-amber-100/80 dark:hover:bg-amber-900/30 transition {{ ($step['done'] ?? false) ? 'opacity-60' : '' }}">
                        @if ($step['done'] ?? false)
                            <span class="w-5 h-5 rounded-full bg-emerald-500 text-white flex items-center justify-center text-xs">✓</span>
                        @else
                            <span class="w-5 h-5 rounded-full border-2 border-amber-500"></span>
                        @endif
                        <span class="text-sm text-amber-950 dark:text-amber-50">{{ $step['label'] }}</span>
                    </a>
                </li>
            @endif
        @endforeach
    </ul>
</div>
@endif
