@props(['queue' => []])

@php $items = collect($queue); @endphp

@if ($items->isNotEmpty())
<div class="rounded-2xl border border-purple-200/80 dark:border-purple-900/60 bg-gradient-to-r from-purple-50 via-white to-violet-50 dark:from-purple-950/40 dark:via-slate-900 dark:to-violet-950/30 p-4 sm:p-5 shadow-sm">
    <div class="flex items-center gap-3 mb-4">
        <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-purple-100 dark:bg-purple-950 text-purple-600 dark:text-purple-400">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        </span>
        <div>
            <p class="text-sm font-semibold text-slate-900 dark:text-white">Needs your attention</p>
            <p class="text-xs text-slate-600 dark:text-slate-400">{{ $items->count() }} open item{{ $items->count() === 1 ? '' : 's' }}</p>
        </div>
    </div>
    <ul class="space-y-2 max-h-80 overflow-y-auto pr-1">
        @foreach ($items as $item)
            <li>
                <a href="{{ $item['url'] }}" class="flex items-center justify-between gap-3 p-3 rounded-xl bg-white/80 dark:bg-slate-900/60 border border-slate-200/80 dark:border-slate-800 hover:border-purple-300 dark:hover:border-purple-700 transition
                    @if(($item['severity'] ?? '') === 'danger') border-l-4 border-l-red-500 @elseif(($item['severity'] ?? '') === 'warning') border-l-4 border-l-amber-500 @else border-l-4 border-l-purple-500 @endif">
                    <span class="text-sm font-medium text-slate-900 dark:text-white">{{ $item['label'] }}</span>
                    <span class="text-xs font-semibold text-purple-600 shrink-0">View →</span>
                </a>
            </li>
        @endforeach
    </ul>
</div>
@else
<div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/50 p-4 sm:p-5 flex items-center gap-3">
    <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </span>
    <div>
        <p class="text-sm font-semibold text-slate-900 dark:text-white">All caught up</p>
        <p class="text-xs text-slate-600 dark:text-slate-400">No overdue invoices, tickets, or hosting issues waiting on you.</p>
    </div>
</div>
@endif
