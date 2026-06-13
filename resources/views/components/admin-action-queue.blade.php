@props(['attention' => []])

@php
    $items = array_filter([
        [
            'label' => 'Domain orders',
            'count' => $attention['domain_orders'] ?? 0,
            'new' => $attention['domain_orders_new'] ?? 0,
            'href' => route('admin.domain-orders.index', ['status' => 'queued']),
            'tone' => 'emerald',
        ],
        [
            'label' => 'Orders',
            'count' => $attention['orders'] ?? 0,
            'new' => $attention['orders_new'] ?? 0,
            'href' => route('admin.orders.index', ['status' => 'pending']),
            'tone' => 'blue',
        ],
        [
            'label' => 'Tickets',
            'count' => $attention['tickets'] ?? 0,
            'new' => $attention['tickets_new'] ?? 0,
            'href' => route('tickets.index'),
            'tone' => 'violet',
        ],
        [
            'label' => 'Payments',
            'count' => $attention['payments'] ?? 0,
            'new' => $attention['payments_new'] ?? 0,
            'href' => route('admin.payments.index'),
            'tone' => 'amber',
        ],
        [
            'label' => 'Renewals',
            'count' => $attention['domain_renewals'] ?? 0,
            'new' => $attention['domain_renewals_new'] ?? 0,
            'href' => route('admin.domain-renewals.index'),
            'tone' => 'cyan',
        ],
        [
            'label' => 'Provisioning',
            'count' => $attention['services_provisioning'] ?? 0,
            'new' => $attention['services_new'] ?? 0,
            'href' => route('admin.services.index'),
            'tone' => 'rose',
        ],
    ], fn ($item) => $item['count'] > 0);

    $pendingTotal = $attention['total'] ?? 0;
    $newTotal = $attention['new_total'] ?? 0;
@endphp

@if(count($items) > 0)
<div class="rounded-2xl border border-emerald-200/80 dark:border-emerald-900/60 bg-gradient-to-r from-emerald-50 via-white to-teal-50 dark:from-emerald-950/40 dark:via-slate-900 dark:to-teal-950/30 p-4 sm:p-5 shadow-sm space-y-3">
    <div class="flex flex-col sm:flex-row sm:items-center gap-4">
        <div class="flex items-center gap-3 shrink-0">
            @if($newTotal > 0)
                <span class="relative flex h-3 w-3">
                    <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex h-3 w-3 rounded-full bg-emerald-500"></span>
                </span>
            @else
                <span class="inline-flex h-3 w-3 rounded-full bg-emerald-500/70"></span>
            @endif
            <div>
                <p class="text-sm font-semibold text-slate-900 dark:text-white">Needs your attention</p>
                <p class="text-xs text-slate-600 dark:text-slate-400">
                    @if($newTotal > 0)
                        {{ $newTotal }} new · {{ $pendingTotal }} open
                    @else
                        {{ $pendingTotal }} open item{{ $pendingTotal === 1 ? '' : 's' }}
                    @endif
                </p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 sm:ml-auto">
            @foreach($items as $item)
                <a href="{{ $item['href'] }}"
                    class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold border transition hover:scale-[1.02]
                    @switch($item['tone'])
                        @case('emerald') bg-emerald-100/80 dark:bg-emerald-950/60 text-emerald-800 dark:text-emerald-200 border-emerald-200 dark:border-emerald-800 hover:bg-emerald-200/80 @break
                        @case('blue') bg-blue-100/80 dark:bg-blue-950/60 text-blue-800 dark:text-blue-200 border-blue-200 dark:border-blue-800 hover:bg-blue-200/80 @break
                        @case('violet') bg-violet-100/80 dark:bg-violet-950/60 text-violet-800 dark:text-violet-200 border-violet-200 dark:border-violet-800 hover:bg-violet-200/80 @break
                        @case('amber') bg-amber-100/80 dark:bg-amber-950/60 text-amber-800 dark:text-amber-200 border-amber-200 dark:border-amber-800 hover:bg-amber-200/80 @break
                        @case('cyan') bg-cyan-100/80 dark:bg-cyan-950/60 text-cyan-800 dark:text-cyan-200 border-cyan-200 dark:border-cyan-800 hover:bg-cyan-200/80 @break
                        @default bg-rose-100/80 dark:bg-rose-950/60 text-rose-800 dark:text-rose-200 border-rose-200 dark:border-rose-800 hover:bg-rose-200/80
                    @endswitch">
                    {{ $item['label'] }}
                    <span class="inline-flex items-center gap-1 min-w-[1.25rem] justify-center rounded-full bg-white/70 dark:bg-slate-900/50 px-1.5 py-0.5 text-[10px]">
                        {{ $item['count'] }}
                        @if($item['new'] > 0)
                            <x-admin-attention-dot />
                        @endif
                    </span>
                </a>
            @endforeach
        </div>
    </div>
    <x-admin-domain-order-stats :breakdown="$attention['domain_order_breakdown'] ?? []" :attention="$attention" />
</div>
@else
<div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50/80 dark:bg-slate-900/50 p-4 sm:p-5 flex items-center gap-3">
    <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-950 text-emerald-600 dark:text-emerald-400">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </span>
    <div>
        <p class="text-sm font-semibold text-slate-900 dark:text-white">All caught up</p>
        <p class="text-xs text-slate-600 dark:text-slate-400">No domain orders, tickets, or payments waiting on you.</p>
    </div>
</div>
@endif
