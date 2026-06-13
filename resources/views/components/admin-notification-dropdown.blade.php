@props(['attention' => []])

@php
    $newTotal = $attention['new_total'] ?? 0;
    $pendingTotal = $attention['total'] ?? 0;
    $recent = $attention['recent'] ?? [];
@endphp

<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button
        type="button"
        @click="open = !open"
        class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition relative"
        :aria-expanded="open"
        aria-label="Admin notifications"
        title="{{ $newTotal > 0 ? $newTotal.' new items' : ($pendingTotal > 0 ? $pendingTotal.' open items' : 'No pending items') }}"
    >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        @if($newTotal > 0)
            <span class="absolute top-1 right-1 flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500 ring-2 ring-white dark:ring-slate-900"></span>
            </span>
        @elseif($pendingTotal > 0)
            <span class="absolute top-1.5 right-1.5 h-2 w-2 rounded-full bg-emerald-500/80"></span>
        @endif
    </button>

    <div
        x-show="open"
        x-cloak
        @click.outside="open = false"
        x-transition
        class="absolute right-0 mt-2 w-80 sm:w-96 bg-white dark:bg-slate-900 rounded-xl shadow-xl border border-slate-200 dark:border-slate-800 z-50 overflow-hidden"
    >
        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between gap-2">
            <div>
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Notifications</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    @if($newTotal > 0)
                        {{ $newTotal }} new · {{ $pendingTotal }} open
                    @elseif($pendingTotal > 0)
                        {{ $pendingTotal }} open item{{ $pendingTotal === 1 ? '' : 's' }}
                    @else
                        All caught up
                    @endif
                </p>
            </div>
            <a href="{{ route('dashboard') }}" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline" @click="open = false">Dashboard</a>
        </div>

        @if(count($recent) > 0)
            <div class="max-h-80 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                @foreach($recent as $item)
                    <a href="{{ $item['url'] }}" @click="open = false" class="block px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/80 transition">
                        <div class="flex items-start gap-3">
                            <span class="mt-1.5 shrink-0 text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded
                                @switch($item['type'])
                                    @case('domain_order') bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300 @break
                                    @case('ticket') bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300 @break
                                    @case('payment') bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300 @break
                                    @default bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300
                                @endswitch">
                                {{ str_replace('_', ' ', $item['type']) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $item['title'] }}</p>
                                <p class="text-xs text-slate-500 dark:text-slate-400 truncate">{{ $item['meta'] }}</p>
                            </div>
                            <span class="text-[10px] text-slate-400 shrink-0">{{ $item['at'] }}</span>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="px-4 py-8 text-center">
                <p class="text-sm font-medium text-slate-700 dark:text-slate-300">All caught up</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">No open orders or tickets right now.</p>
            </div>
        @endif
    </div>
</div>
