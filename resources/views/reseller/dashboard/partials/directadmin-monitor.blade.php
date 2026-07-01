@php
    $m = $directAdminMonitor ?? [];
    $chart = $m['chart'] ?? ['labels' => [], 'payments' => [], 'disk_gb' => [], 'hosted_users' => []];
    $connected = (bool) ($m['is_connected'] ?? false);
    $deferLoad = (bool) ($m['defer_load'] ?? false);
@endphp

<div
    class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden"
    @if ($connected)
        x-data="resellerDirectAdminLive(@js([
            'live_url' => $m['live_url'] ?? '',
            'panel_url' => $m['panel_url'] ?? '',
            'defer_load' => $deferLoad,
            'payments_today' => $m['payments_today'] ?? 0,
            'payments_7d' => $m['payments_7d'] ?? 0,
            'payments_30d' => $m['payments_30d'] ?? 0,
            'disk_used_gb' => $m['disk_used_gb'] ?? null,
            'disk_pool_gb' => $m['disk_pool_gb'] ?? 0,
            'disk_pool_percent' => $m['disk_pool_percent'] ?? null,
            'hosted_user_count' => $m['hosted_user_count'] ?? null,
            'max_users' => $m['max_users'] ?? 0,
            'api_reachable' => $m['api_reachable'] ?? false,
            'provisioning_ready' => $m['provisioning_ready'] ?? false,
            'updated_at' => $m['updated_at'] ?? now()->toIso8601String(),
            'chart' => $chart,
        ]))"
        x-init="init()"
    @endif
>
    <div class="relative overflow-hidden border-b border-slate-200 dark:border-slate-800">
        <div class="absolute inset-0 bg-gradient-to-br from-violet-500/10 via-fuchsia-500/5 to-emerald-500/10 dark:from-violet-500/20 dark:to-emerald-500/10"></div>
        <div class="relative p-6 flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
            <div>
                <div class="flex items-center gap-2 mb-2">
                    <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-violet-600 text-white">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/></svg>
                    </span>
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Server pulse</h2>
                    @if ($connected)
                        <span
                            class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-medium"
                            :class="statusBadgeClass"
                        >
                            <span class="w-1.5 h-1.5 rounded-full animate-pulse" :class="statusDotClass"></span>
                            <span x-text="statusLabel"></span>
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span>
                            Not linked
                        </span>
                    @endif
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-400 max-w-xl">
                    Live view of your DirectAdmin footprint — customer payments, disk use, and hosted accounts on your node.
                </p>
                @if (! empty($m['node_hostname']))
                    <p class="text-xs text-slate-500 mt-2 font-mono">
                        {{ ($m['node_name'] ?? 'Node') }} · {{ $m['directadmin_username'] ?? 'reseller' }} @ {{ $m['node_hostname'] }}
                    </p>
                @endif
            </div>
            @if ($connected)
                <div class="flex items-center gap-2 text-xs text-slate-500">
                    <span x-show="polling" x-cloak class="inline-flex items-center gap-1 text-violet-600">
                        <svg class="w-3 h-3 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        Syncing…
                    </span>
                    <span x-text="lastUpdatedLabel"></span>
                    <button type="button" @click="refresh()" class="px-2.5 py-1 rounded-lg border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-800 transition">Refresh</button>
                </div>
            @endif
        </div>
    </div>

    @if (! $connected)
        <div class="p-8 text-center">
            <div class="mx-auto w-14 h-14 rounded-2xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center mb-4">
                <svg class="w-7 h-7 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <p class="font-medium text-slate-900 dark:text-white">DirectAdmin not linked yet</p>
            <p class="text-sm text-slate-500 mt-2 max-w-md mx-auto">Ask your platform admin to connect your reseller account on the Node tab. Once linked, this panel shows live disk, accounts, and payment trends.</p>
        </div>
    @else
        <div class="p-6 space-y-6 relative">
            <div
                x-show="panelLoading"
                x-cloak
                class="absolute inset-0 z-10 flex items-center justify-center bg-white/80 dark:bg-slate-900/80 rounded-b-xl"
            >
                <div class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                    <svg class="w-5 h-5 animate-spin text-violet-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    Loading server metrics…
                </div>
            </div>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="rounded-xl border border-violet-200/70 dark:border-violet-900/50 bg-violet-50/50 dark:bg-violet-950/30 p-4">
                    <p class="text-xs font-medium text-violet-700 dark:text-violet-300">Payments today</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1" x-text="formatKes(paymentsToday)"></p>
                    <p class="text-[11px] text-slate-500 mt-1">Customer collections</p>
                </div>
                <div class="rounded-xl border border-emerald-200/70 dark:border-emerald-900/50 bg-emerald-50/50 dark:bg-emerald-950/30 p-4">
                    <p class="text-xs font-medium text-emerald-700 dark:text-emerald-300">Disk on server</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">
                        <span x-text="diskUsedLabel"></span>
                        @if (($m['disk_pool_gb'] ?? 0) > 0)
                            <span class="text-sm font-normal text-slate-500">/ {{ $m['disk_pool_gb'] }} GB</span>
                        @endif
                    </p>
                    @if (($m['disk_pool_percent'] ?? null) !== null)
                        <div class="mt-2 h-1.5 bg-slate-200 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div
                                class="h-full rounded-full transition-all duration-500"
                                :class="diskBarClass"
                                :style="'width:' + Math.min(100, diskPoolPercent || 0) + '%'"
                            ></div>
                        </div>
                    @endif
                </div>
                <div class="rounded-xl border border-amber-200/70 dark:border-amber-900/50 bg-amber-50/50 dark:bg-amber-950/30 p-4">
                    <p class="text-xs font-medium text-amber-700 dark:text-amber-300">Hosted accounts</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">
                        <span x-text="hostedUsersLabel"></span>
                        @if (($m['max_users'] ?? 0) > 0)
                            <span class="text-sm font-normal text-slate-500">/ {{ $m['max_users'] }}</span>
                        @endif
                    </p>
                    <p class="text-[11px] text-slate-500 mt-1">DirectAdmin users</p>
                </div>
                <div class="rounded-xl border border-slate-200 dark:border-slate-700 bg-slate-50/80 dark:bg-slate-800/50 p-4">
                    <p class="text-xs font-medium text-slate-600 dark:text-slate-300">30-day collections</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1" x-text="formatKes(payments30d)"></p>
                    <p class="text-[11px] text-slate-500 mt-1">7d: <span x-text="formatKes(payments7d)"></span></p>
                </div>
            </div>

            <div>
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900 dark:text-white">14-day trends</h3>
                        <p class="text-xs text-slate-500">Payments, disk usage, and hosted accounts</p>
                    </div>
                    <div class="flex flex-wrap gap-3 text-[11px]">
                        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 bg-violet-500 rounded"></span> Payments (KES)</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 bg-emerald-500 rounded"></span> Disk (GB)</span>
                        <span class="inline-flex items-center gap-1.5"><span class="w-3 h-0.5 bg-amber-500 rounded"></span> Accounts</span>
                    </div>
                </div>
                <div class="h-56 sm:h-64 relative">
                    <canvas id="resellerDaMonitorChart" aria-label="DirectAdmin trends chart"></canvas>
                </div>
            </div>
        </div>
    @endif
</div>

@once
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
window.resellerDirectAdminLive = function (config) {
    return {
        apiReachable: config.api_reachable || false,
        provisioningReady: config.provisioning_ready || false,
        paymentsToday: config.payments_today || 0,
        payments7d: config.payments_7d || 0,
        payments30d: config.payments_30d || 0,
        diskUsedGb: config.disk_used_gb,
        diskPoolGb: config.disk_pool_gb || 0,
        diskPoolPercent: config.disk_pool_percent,
        hostedUserCount: config.hosted_user_count,
        maxUsers: config.max_users || 0,
        liveUrl: config.live_url,
        panelUrl: config.panel_url,
        deferLoad: Boolean(config.defer_load),
        panelLoading: Boolean(config.defer_load),
        chartConfig: config.chart || { labels: [], payments: [], disk_gb: [], hosted_users: [] },
        pollTimer: null,
        polling: false,
        lastUpdated: config.updated_at ? new Date(config.updated_at) : new Date(),

        get statusLabel() {
            if (!this.apiReachable) return 'API unreachable';
            if (!this.provisioningReady) return 'Setup incomplete';
            return 'Live';
        },
        get statusBadgeClass() {
            if (!this.apiReachable) return 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200';
            if (!this.provisioningReady) return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300';
            return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';
        },
        get statusDotClass() {
            if (!this.apiReachable) return 'bg-amber-500';
            if (!this.provisioningReady) return 'bg-slate-400';
            return 'bg-emerald-500';
        },
        get diskUsedLabel() {
            return this.diskUsedGb !== null && this.diskUsedGb !== undefined
                ? Number(this.diskUsedGb).toFixed(1) + ' GB'
                : '—';
        },
        get hostedUsersLabel() {
            return this.hostedUserCount !== null && this.hostedUserCount !== undefined
                ? String(this.hostedUserCount)
                : '—';
        },
        get diskBarClass() {
            const p = this.diskPoolPercent || 0;
            if (p >= 90) return 'bg-amber-500';
            return 'bg-emerald-500';
        },
        get lastUpdatedLabel() {
            if (!this.lastUpdated) return '';
            return 'Updated ' + this.lastUpdated.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        },
        formatKes(amount) {
            return 'KES ' + Number(amount || 0).toLocaleString(undefined, { maximumFractionDigits: 0 });
        },
        init() {
            if (this.deferLoad && this.panelUrl) {
                this.loadPanel().then(() => {
                    this.pollTimer = setInterval(() => this.refresh(), 45000);
                });
            } else {
                this.bootChart();
                this.pollTimer = setInterval(() => this.refresh(), 45000);
            }
        },
        destroy() {
            if (this.pollTimer) clearInterval(this.pollTimer);
        },
        applyPanel(data) {
            this.apiReachable = data.api_reachable;
            this.provisioningReady = data.provisioning_ready;
            this.paymentsToday = data.payments_today ?? 0;
            this.payments7d = data.payments_7d ?? 0;
            this.payments30d = data.payments_30d ?? 0;
            this.diskUsedGb = data.disk_used_gb;
            this.diskPoolGb = data.disk_pool_gb || 0;
            this.diskPoolPercent = data.disk_pool_percent;
            this.hostedUserCount = data.hosted_user_count;
            this.maxUsers = data.max_users || 0;
            this.lastUpdated = data.updated_at ? new Date(data.updated_at) : new Date();
            if (data.chart) {
                this.chartConfig = data.chart;
            }
        },
        async loadPanel() {
            if (!this.panelUrl) {
                this.panelLoading = false;
                return;
            }
            this.panelLoading = true;
            try {
                const res = await fetch(this.panelUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (res.ok) {
                    this.applyPanel(await res.json());
                    this.bootChart();
                }
            } catch (e) {
                /* silent */
            } finally {
                this.panelLoading = false;
            }
        },
        bootChart() {
            if (typeof window.initResellerDaMonitorChart === 'function') {
                window.initResellerDaMonitorChart(this.chartConfig);
            }
        },
        applyLive(data) {
            this.apiReachable = data.api_reachable;
            this.paymentsToday = data.payments_today;
            this.diskUsedGb = data.disk_used_gb;
            this.diskPoolGb = data.disk_pool_gb || 0;
            this.diskPoolPercent = data.disk_pool_percent;
            this.hostedUserCount = data.hosted_user_count;
            this.maxUsers = data.max_users || 0;
            this.lastUpdated = data.updated_at ? new Date(data.updated_at) : new Date();

            if (typeof window.updateResellerDaMonitorChart === 'function') {
                window.updateResellerDaMonitorChart(data);
            }
        },
        async refresh() {
            if (!this.liveUrl || this.polling) return;
            this.polling = true;
            try {
                const res = await fetch(this.liveUrl, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (!res.ok) return;
                this.applyLive(await res.json());
            } catch (e) {
                /* silent */
            } finally {
                this.polling = false;
            }
        },
    };
};

@if ($connected)
(function () {
    const canvasId = 'resellerDaMonitorChart';

    function normalizeSeries(values) {
        return (values || []).map((value) => (value === null || value === undefined ? NaN : Number(value)));
    }

    window.initResellerDaMonitorChart = function (chartConfig) {
        const canvas = document.getElementById(canvasId);
        if (!canvas || typeof Chart === 'undefined') {
            return false;
        }

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            return false;
        }

        chartConfig = chartConfig || { labels: [], payments: [], disk_gb: [], hosted_users: [] };

        if (window.resellerDaMonitorChart instanceof Chart) {
            window.resellerDaMonitorChart.destroy();
        }

        const grid = document.documentElement.classList.contains('dark')
            ? 'rgba(148,163,184,0.15)'
            : 'rgba(148,163,184,0.25)';

        window.resellerDaMonitorChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartConfig.labels || [],
                datasets: [
                    {
                        label: 'Payments',
                        data: normalizeSeries(chartConfig.payments),
                        borderColor: 'rgb(139, 92, 246)',
                        backgroundColor: 'rgba(139, 92, 246, 0.12)',
                        fill: true,
                        tension: 0.35,
                        yAxisID: 'y',
                        pointRadius: 2,
                    },
                    {
                        label: 'Disk GB',
                        data: normalizeSeries(chartConfig.disk_gb),
                        borderColor: 'rgb(16, 185, 129)',
                        backgroundColor: 'transparent',
                        tension: 0.35,
                        yAxisID: 'y1',
                        spanGaps: true,
                        pointRadius: 2,
                    },
                    {
                        label: 'Hosted accounts',
                        data: normalizeSeries(chartConfig.hosted_users),
                        borderColor: 'rgb(245, 158, 11)',
                        backgroundColor: 'transparent',
                        tension: 0.35,
                        yAxisID: 'y1',
                        spanGaps: true,
                        pointRadius: 2,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label(ctx) {
                                const v = ctx.parsed.y;
                                if (v === null || v === undefined || Number.isNaN(v)) {
                                    return ctx.dataset.label + ': —';
                                }
                                if (ctx.dataset.label === 'Payments') {
                                    return 'Payments: KES ' + Number(v).toLocaleString();
                                }
                                if (ctx.dataset.label === 'Disk GB') {
                                    return 'Disk: ' + Number(v).toFixed(1) + ' GB';
                                }
                                return 'Accounts: ' + v;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        grid: { color: grid },
                        ticks: { maxTicksLimit: 7, font: { size: 10 } },
                    },
                    y: {
                        position: 'left',
                        beginAtZero: true,
                        grid: { color: grid },
                        ticks: {
                            font: { size: 10 },
                            callback: (v) => 'K' + Number(v).toLocaleString(),
                        },
                    },
                    y1: {
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        ticks: { font: { size: 10 } },
                    },
                },
            },
        });

        return true;
    };

    window.updateResellerDaMonitorChart = function (data) {
        const chart = window.resellerDaMonitorChart;
        if (!(chart instanceof Chart)) return;

        const last = chart.data.labels.length - 1;
        if (last < 0) return;

        if (data.disk_used_gb !== null && data.disk_used_gb !== undefined) {
            chart.data.datasets[1].data[last] = Number(data.disk_used_gb);
        }
        if (data.hosted_user_count !== null && data.hosted_user_count !== undefined) {
            chart.data.datasets[2].data[last] = Number(data.hosted_user_count);
        }
        chart.update('none');
    };

    @if (! $deferLoad)
    (function bootChart(retries) {
        if (window.initResellerDaMonitorChart(@json($chart))) return;
        if (retries > 0) {
            setTimeout(() => bootChart(retries - 1), 100);
        }
    })(20);
    @endif
})();
@endif
</script>
@endpush
@endonce
