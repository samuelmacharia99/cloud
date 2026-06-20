@props(['insight', 'showUpgradeCta' => false])

@if (!empty($insight['alerts']) || !empty($insight['disk']) || !empty($insight['bandwidth']) || !empty($insight['database']))
    <div {{ $attributes->merge(['class' => 'bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4']) }}>
        <div class="flex items-center justify-between gap-3">
            <h2 class="font-semibold text-slate-900 dark:text-white">Plan usage</h2>
            @if ($insight['is_suspended'] ?? false)
                <span class="text-xs font-semibold uppercase tracking-wide px-2.5 py-1 rounded-full bg-red-100 text-red-700 dark:bg-red-950/60 dark:text-red-300">Suspended</span>
            @elseif ($insight['needs_upgrade'] ?? false)
                <span class="text-xs font-semibold uppercase tracking-wide px-2.5 py-1 rounded-full bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-200">Upgrade recommended</span>
            @endif
        </div>

        @if (!empty($insight['suspension_message']))
            <p class="text-sm text-slate-600 dark:text-slate-400">
                <span class="font-medium text-slate-900 dark:text-white">Reason:</span> {{ $insight['suspension_message'] }}
            </p>
        @elseif (!empty($insight['suspension_label']))
            <p class="text-sm text-slate-600 dark:text-slate-400">
                <span class="font-medium text-slate-900 dark:text-white">Reason:</span> {{ $insight['suspension_label'] }}
            </p>
        @endif

        @foreach ([
            'disk' => 'Storage',
            'bandwidth' => 'Bandwidth',
            'database' => 'Databases',
        ] as $metricKey => $metricLabel)
            @php $metric = $insight[$metricKey] ?? null; @endphp
            @if (!empty($metric))
                @if ($metricKey === 'database' || !($metric['unlimited'] ?? false))
                <div>
                    <div class="flex items-center justify-between text-sm mb-2">
                        <span class="text-slate-600 dark:text-slate-400">{{ $metricLabel }}</span>
                        <span class="font-medium text-slate-900 dark:text-white">
                            @if ($metricKey === 'database')
                                @if (($metric['unlimited'] ?? false) || empty($metric['limit']))
                                    {{ $metric['used'] }} in use
                                @else
                                    {{ $metric['used'] }} / {{ $metric['limit'] }} ({{ $metric['percent'] }}%)
                                @endif
                            @else
                                {{ number_format($metric['used_mb'] ?? $metric['used'], 0) }} MB / {{ number_format($metric['limit_mb'] ?? $metric['limit'], 0) }} MB ({{ $metric['percent'] }}%)
                            @endif
                        </span>
                    </div>
                    @if (!($metric['unlimited'] ?? false) && ($metric['percent'] ?? null) !== null)
                    <div class="h-2 rounded-full bg-slate-200 dark:bg-slate-700 overflow-hidden">
                        <div class="h-full rounded-full {{ ($metric['percent'] ?? 0) >= 100 ? 'bg-red-500' : (($metric['percent'] ?? 0) >= 90 ? 'bg-amber-500' : 'bg-emerald-500') }}"
                            style="width: {{ min(100, $metric['percent'] ?? 0) }}%"></div>
                    </div>
                    @endif
                </div>
                @endif
            @endif
        @endforeach

        @if (!empty($insight['alerts']))
            <ul class="space-y-2">
                @foreach ($insight['alerts'] as $alert)
                    <li class="text-sm px-3 py-2 rounded-lg {{ $alert['level'] === 'danger' ? 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200' : 'bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200' }}">
                        {{ $alert['message'] }}
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($showUpgradeCta && !empty($insight['needs_upgrade']) && !empty($insight['upgrade_url']))
            <a href="{{ $insight['upgrade_url'] }}" class="inline-flex btn-sm btn-primary">Upgrade plan</a>
        @endif
    </div>
@endif
