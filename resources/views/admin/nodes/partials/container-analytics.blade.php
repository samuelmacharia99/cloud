@php
    $capacity = $containerAnalytics['capacity'];
    $fleet = $containerAnalytics['fleet'];
    $insights = $containerAnalytics['insights'];
    $topConsumers = $containerAnalytics['top_consumers'];
    $attention = $containerAnalytics['attention'];
    $chart = $containerAnalytics['chart'];
    $threshold = $containerAnalytics['scale_out_threshold'];
    $pressureColor = $capacity['pressure_percent'] >= $threshold
        ? 'bg-amber-500'
        : ($capacity['pressure_percent'] >= 50 ? 'bg-blue-500' : 'bg-emerald-500');
@endphp

<div class="space-y-6">
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="ui-card p-5">
            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Runtimes</p>
            <p class="text-2xl font-semibold text-slate-900 dark:text-white mt-1">{{ $fleet['running'] }}<span class="text-base font-medium text-slate-400"> / {{ $fleet['total'] }}</span></p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Running of non-terminated</p>
        </div>
        <div class="ui-card p-5">
            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Live pressure</p>
            <p class="text-2xl font-semibold text-slate-900 dark:text-white mt-1">{{ $capacity['pressure_percent'] }}%</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Alert at {{ $threshold }}% (live + sold disk)</p>
        </div>
        <div class="ui-card p-5">
            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Failed</p>
            <p class="text-2xl font-semibold {{ $fleet['failed'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }} mt-1">{{ $fleet['failed'] }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $fleet['stopped'] }} stopped · {{ $fleet['deploying'] }} deploying</p>
        </div>
        <div class="ui-card p-5">
            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Sold disk</p>
            <p class="text-2xl font-semibold text-slate-900 dark:text-white mt-1">{{ $capacity['reserved']['storage'] }}%</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">{{ $capacity['reserved_absolute']['storage_gb'] }} GB of {{ $node->storage_gb }} GB</p>
        </div>
    </div>

    @if (count($insights) > 0)
        <div class="space-y-2">
            @foreach ($insights as $insight)
                @php
                    $insightClass = match ($insight['severity']) {
                        'critical' => 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-950/40 text-red-900 dark:text-red-100',
                        'warning' => 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 text-amber-950 dark:text-amber-100',
                        default => 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/60 text-slate-800 dark:text-slate-200',
                    };
                @endphp
                <div class="rounded-xl border px-4 py-3 {{ $insightClass }}">
                    <p class="text-sm font-semibold">{{ $insight['title'] }}</p>
                    <p class="text-sm mt-0.5 opacity-90">{{ $insight['detail'] }}</p>
                </div>
            @endforeach
        </div>
    @endif

    <div class="ui-card p-6 sm:p-8">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3 mb-6">
            <div>
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Live vs sold capacity</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                    Placement uses <strong class="font-medium text-slate-800 dark:text-slate-200">live CPU and RAM</strong> plus <strong class="font-medium text-slate-800 dark:text-slate-200">sold disk</strong>.
                    Plan CPU/RAM allowances are allowed to oversubscribe.
                </p>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-400 shrink-0">Pressure {{ $capacity['pressure_percent'] }}%</p>
        </div>

        <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2 mb-8">
            <div class="{{ $pressureColor }} h-2 rounded-full" style="width: {{ min($capacity['pressure_percent'], 100) }}%"></div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            @foreach ([
                'cpu' => ['label' => 'CPU', 'live' => $capacity['live']['cpu'], 'sold' => $capacity['reserved']['cpu'], 'sold_label' => $capacity['reserved_absolute']['cpu_cores'].' cores sold / '.$node->cpu_cores.' cores'],
                'ram' => ['label' => 'RAM', 'live' => $capacity['live']['ram'], 'sold' => $capacity['reserved']['ram'], 'sold_label' => $capacity['reserved_absolute']['ram_gb'].' GB sold / '.$node->ram_gb.' GB'],
                'storage' => ['label' => 'Disk', 'live' => $capacity['live']['storage'], 'sold' => $capacity['reserved']['storage'], 'sold_label' => $capacity['reserved_absolute']['storage_gb'].' GB sold / '.$node->storage_gb.' GB'],
            ] as $metric)
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-medium text-slate-900 dark:text-white">{{ $metric['label'] }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Live {{ $metric['live'] }}%</p>
                    </div>
                    <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2 mb-2">
                        <div class="bg-blue-500 h-2 rounded-full" style="width: {{ min($metric['live'], 100) }}%"></div>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400 mb-1">Sold {{ $metric['sold'] }}%{{ $metric['sold'] > 100 ? ' (oversubscribed)' : '' }}</p>
                    <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-1.5">
                        <div class="{{ $metric['sold'] > 100 ? 'bg-violet-500' : 'bg-slate-400 dark:bg-slate-500' }} h-1.5 rounded-full" style="width: {{ min($metric['sold'], 100) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">{{ $metric['sold_label'] }}</p>
                </div>
            @endforeach
        </div>
    </div>

    <div class="ui-card p-6 sm:p-8">
        <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Host trend (last 24 hours)</h2>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">From two-minute SSH health polls. CPU, RAM, and disk on this machine — not per-container docker stats.</p>
        @if (count($chart['labels']) > 1)
            <div class="h-56">
                <canvas id="nodeHostTrendChart"></canvas>
            </div>
        @else
            <p class="text-sm text-slate-500 dark:text-slate-400 py-8 text-center">Not enough poll samples yet for a trend. Use Test Health or wait for the next poll.</p>
        @endif
    </div>

    <div class="grid grid-cols-1 {{ count($attention) > 0 ? 'lg:grid-cols-5' : '' }} gap-6">
        <div class="ui-card p-6 {{ count($attention) > 0 ? 'lg:col-span-3' : '' }}">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Top consumers (24h)</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Average CPU from docker stats. Peak RAM and disk are the highest sample in the window.</p>
            @if (collect($topConsumers)->contains(fn ($row) => $row['avg_cpu'] !== null))
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 dark:border-slate-800">
                                <th class="text-left py-2 font-semibold text-slate-600 dark:text-slate-400">Service</th>
                                <th class="text-right py-2 font-semibold text-slate-600 dark:text-slate-400">Avg CPU</th>
                                <th class="text-right py-2 font-semibold text-slate-600 dark:text-slate-400">Peak RAM</th>
                                <th class="text-right py-2 font-semibold text-slate-600 dark:text-slate-400">Peak disk</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:border-slate-800">
                            @foreach ($topConsumers as $row)
                                <tr>
                                    <td class="py-2.5">
                                        @if ($row['service_id'])
                                            <a href="{{ route('admin.services.show', $row['service_id']) }}" class="font-medium text-slate-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400">{{ $row['service_name'] }}</a>
                                        @else
                                            <span class="font-medium text-slate-900 dark:text-white">{{ $row['service_name'] }}</span>
                                        @endif
                                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $row['customer_name'] ?? '—' }} · {{ ucfirst($row['status']) }}</p>
                                    </td>
                                    <td class="py-2.5 text-right font-mono text-slate-800 dark:text-slate-200">{{ $row['avg_cpu'] === null ? '—' : $row['avg_cpu'].'%' }}</td>
                                    <td class="py-2.5 text-right font-mono text-slate-800 dark:text-slate-200">{{ $row['peak_memory_mb'] === null ? '—' : $row['peak_memory_mb'].' MB' }}</td>
                                    <td class="py-2.5 text-right font-mono text-slate-800 dark:text-slate-200">{{ $row['peak_disk_gb'] === null ? '—' : $row['peak_disk_gb'].' GB' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400 py-6 text-center">No docker stats for these runtimes in the last 24 hours.</p>
            @endif
        </div>

        @if (count($attention) > 0)
            <div class="ui-card p-6 lg:col-span-2">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-1">Needs attention</h2>
                <p class="text-sm text-slate-500 dark:text-slate-400 mb-4">Failed or stopped runtimes on this host.</p>
                <ul class="space-y-3">
                    @foreach ($attention as $row)
                        <li class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                @if ($row['service_id'])
                                    <a href="{{ route('admin.services.show', $row['service_id']) }}" class="text-sm font-medium text-slate-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 truncate block">{{ $row['service_name'] }}</a>
                                @else
                                    <p class="text-sm font-medium text-slate-900 dark:text-white truncate">{{ $row['service_name'] }}</p>
                                @endif
                                <p class="text-xs text-slate-500 dark:text-slate-400">{{ $row['customer_name'] ?? '—' }}</p>
                            </div>
                            <span class="shrink-0 px-2 py-0.5 rounded-full text-xs font-medium
                                {{ $row['status'] === 'failed' ? 'bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300' : 'bg-amber-100 dark:bg-amber-950 text-amber-800 dark:text-amber-300' }}">
                                {{ ucfirst($row['status']) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</div>

@if (count($chart['labels']) > 1)
    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('nodeHostTrendChart');
            if (!canvas || typeof Chart === 'undefined') {
                return;
            }
            const isDark = document.documentElement.classList.contains('dark');
            const tick = isDark ? '#94a3b8' : '#64748b';
            const grid = isDark ? 'rgba(148, 163, 184, 0.12)' : 'rgba(15, 23, 42, 0.08)';
            new Chart(canvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: @json($chart['labels']),
                    datasets: [
                        { label: 'CPU %', data: @json($chart['cpu']), borderColor: 'rgb(59, 130, 246)', backgroundColor: 'rgba(59, 130, 246, 0.08)', borderWidth: 1.5, tension: 0.3, fill: false, pointRadius: 0 },
                        { label: 'RAM %', data: @json($chart['ram']), borderColor: 'rgb(245, 158, 11)', backgroundColor: 'rgba(245, 158, 11, 0.08)', borderWidth: 1.5, tension: 0.3, fill: false, pointRadius: 0 },
                        { label: 'Disk %', data: @json($chart['storage']), borderColor: 'rgb(16, 185, 129)', backgroundColor: 'rgba(16, 185, 129, 0.08)', borderWidth: 1.5, tension: 0.3, fill: false, pointRadius: 0 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: true, position: 'top', labels: { color: tick } } },
                    scales: {
                        x: { ticks: { color: tick, maxRotation: 0, autoSkip: true, maxTicksLimit: 12 }, grid: { color: grid } },
                        y: { beginAtZero: true, max: 100, ticks: { color: tick }, grid: { color: grid } }
                    }
                }
            });
        });
    </script>
    @endpush
@endif
