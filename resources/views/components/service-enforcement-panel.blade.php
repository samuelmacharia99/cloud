@props(['insight'])

@if (!empty($insight['alerts']) || !empty($insight['disk']))
    <div {{ $attributes->merge(['class' => 'bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4']) }}>
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-slate-900 dark:text-white">Enforcement &amp; usage</h2>
            @if ($insight['is_suspended'] ?? false)
                <span class="text-xs font-semibold uppercase tracking-wide px-2.5 py-1 rounded-full bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300">Suspended</span>
            @endif
        </div>

        @if (!empty($insight['suspension_label']))
            <p class="text-sm text-slate-600 dark:text-slate-400">
                <span class="font-medium text-slate-900 dark:text-white">Reason:</span> {{ $insight['suspension_label'] }}
            </p>
        @endif

        @if (!empty($insight['disk']))
            <div>
                <div class="flex items-center justify-between text-sm mb-2">
                    <span class="text-slate-600 dark:text-slate-400">Disk usage</span>
                    <span class="font-medium text-slate-900 dark:text-white">
                        {{ number_format($insight['disk']['used_mb'], 1) }} MB
                        @if ($insight['disk']['limit_mb'])
                            / {{ number_format($insight['disk']['limit_mb'], 1) }} MB
                            ({{ $insight['disk']['percent'] }}%)
                        @else
                            (unlimited)
                        @endif
                    </span>
                </div>
                @if ($insight['disk']['limit_mb'])
                    <div class="h-2 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                        <div class="h-full rounded-full {{ ($insight['disk']['percent'] ?? 0) >= 100 ? 'bg-red-500' : (($insight['disk']['percent'] ?? 0) >= 85 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                            style="width: {{ min(100, $insight['disk']['percent'] ?? 0) }}%"></div>
                    </div>
                @endif
            </div>
        @endif

        @if (!empty($insight['alerts']))
            <ul class="space-y-2">
                @foreach ($insight['alerts'] as $alert)
                    <li class="text-sm px-3 py-2 rounded-lg {{ $alert['level'] === 'danger' ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200' }}">
                        {{ $alert['message'] }}
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif
