@props([
    'unread' => 0,
    'recent' => [],
    'nextSteps' => [],
])

@php
    $unread = (int) $unread;
    $recent = collect($recent);
    $nextSteps = collect($nextSteps)->take(4);
@endphp

<div class="relative" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button
        type="button"
        @click="open = !open"
        class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition relative"
        :aria-expanded="open"
        aria-label="Notifications"
        title="{{ $unread > 0 ? $unread.' unread' : 'Notifications' }}"
    >
        <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        @if($unread > 0)
            <span class="absolute top-1 right-1 flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-teal-400 opacity-75"></span>
                <span class="relative inline-flex h-2.5 w-2.5 rounded-full bg-teal-500 ring-2 ring-white dark:ring-slate-900"></span>
            </span>
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
                    @if($unread > 0)
                        {{ $unread }} unread
                    @else
                        You're up to date
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-2">
                @if($unread > 0)
                    <form method="POST" action="{{ route('customer.notifications.read-all') }}">
                        @csrf
                        <button type="submit" class="text-xs font-medium text-teal-700 dark:text-teal-300 hover:underline">Mark all read</button>
                    </form>
                @endif
                <a href="{{ route('customer.notifications.index') }}" class="text-xs font-medium text-slate-600 dark:text-slate-300 hover:underline" @click="open = false">View all</a>
            </div>
        </div>

        @if($nextSteps->isNotEmpty())
            <div class="px-4 py-2 bg-slate-50 dark:bg-slate-800/60 border-b border-slate-200 dark:border-slate-800">
                <p class="text-[10px] font-semibold uppercase tracking-wide text-slate-500 mb-2">Do next</p>
                <div class="space-y-1.5">
                    @foreach($nextSteps as $step)
                        <a href="{{ $step['url'] }}" @click="open = false" class="block text-xs text-slate-700 dark:text-slate-200 hover:text-teal-700 dark:hover:text-teal-300 truncate">
                            {{ $step['title'] }} — {{ $step['body'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if($recent->isNotEmpty())
            <div class="max-h-72 overflow-y-auto divide-y divide-slate-100 dark:divide-slate-800">
                @foreach($recent as $item)
                    <form method="POST" action="{{ route('customer.notifications.read', $item) }}">
                        @csrf
                        <button type="submit" class="w-full text-left px-4 py-3 hover:bg-slate-50 dark:hover:bg-slate-800/80 transition {{ $item->isUnread() ? 'bg-teal-50/40 dark:bg-teal-950/20' : '' }}">
                            <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $item->title }}</p>
                            @if($item->body)
                                <p class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5">{{ $item->body }}</p>
                            @endif
                            <p class="text-[10px] text-slate-400 mt-1">{{ $item->created_at?->diffForHumans() }}</p>
                        </button>
                    </form>
                @endforeach
            </div>
        @else
            <div class="px-4 py-8 text-center">
                <p class="text-sm font-medium text-slate-700 dark:text-slate-300">No notifications yet</p>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Invoice, service, and ticket updates will show up here.</p>
            </div>
        @endif
    </div>
</div>
