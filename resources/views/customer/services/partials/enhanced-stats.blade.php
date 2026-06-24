<div class="space-y-6">
    <div
        x-data="containerDashboard()"
        x-init="init()"
        class="space-y-6"
    >
        <!-- Health Banner -->
        <div class="px-6 py-4 rounded-lg border-l-4 transition-all"
             :class="health?.health_score >= 80 ? 'bg-green-50 dark:bg-green-900/20 border-green-500' :
                     health?.health_score >= 50 ? 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-500' :
                     'bg-red-50 dark:bg-red-900/20 border-red-500'">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div class="flex items-center gap-4">
                    <div class="relative flex h-3 w-3">
                        <template x-if="health?.status === 'running'">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        </template>
                        <span :class="{
                            'bg-green-500': health?.status === 'running',
                            'bg-yellow-400': health?.status === 'stopped',
                            'bg-blue-500': health?.status === 'deploying',
                            'bg-red-500': health?.status === 'failed'
                        }" class="relative inline-flex rounded-full h-3 w-3"></span>
                    </div>
                    <div>
                        <h3 class="font-semibold text-slate-900 dark:text-white" x-text="health ? `${health.status?.toUpperCase()} — Health: ${health.health_score}%` : 'Loading health…'"></h3>
                        <p class="text-xs text-slate-600 dark:text-slate-400" x-text="health?.last_check_human ? `Last checked: ${health.last_check_human}` : 'Fetching status…'"></p>
                    </div>
                </div>
                <button @click="toggleAutoRefresh()" :class="autoRefresh ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400'"
                        class="px-3 py-1.5 rounded text-sm font-medium transition shrink-0">
                    <span x-show="autoRefresh" class="inline-block w-2 h-2 bg-current rounded-full mr-1.5 animate-pulse"></span>
                    <span x-text="autoRefresh ? 'Live refresh: ON' : 'Live refresh: OFF'"></span>
                </button>
            </div>
        </div>

        <!-- Incident Alert -->
        <template x-if="health?.incident_level && health.incident_level !== 'none'">
            <div :class="health?.incident_level === 'critical' ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800' : 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800'"
                 class="px-6 py-4 rounded-lg border flex items-start gap-4">
                <div :class="health?.incident_level === 'critical' ? 'text-red-600 dark:text-red-400' : 'text-yellow-600 dark:text-yellow-400'" class="text-xl flex-shrink-0">
                    <span x-text="health?.incident_level === 'critical' ? 'Critical' : 'Warning'"></span>
                </div>
                <div>
                    <h4 :class="health?.incident_level === 'critical' ? 'text-red-800 dark:text-red-200' : 'text-yellow-800 dark:text-yellow-200'" class="font-semibold" x-text="health?.incident_level === 'critical' ? 'Critical issue' : 'Warning'"></h4>
                    <p :class="health?.incident_level === 'critical' ? 'text-red-700 dark:text-red-300' : 'text-yellow-700 dark:text-yellow-300'" class="text-sm mt-1" x-text="health?.incident_message"></p>
                </div>
            </div>
        </template>

        <!-- Primary KPI Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">CPU</p>
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1" x-text="`${summary?.cpu_percentage || 0}%`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="`Peak ${summary?.cpu_peak || 0}%`"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">Memory</p>
                <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1" x-text="`${summary?.memory_used_mb || 0} MB`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="`${Math.round((summary?.memory_used_mb || 0) / (summary?.memory_limit_mb || {{ $containerLimits['memory_mb'] }} || 1) * 100)}% of limit`"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">Storage</p>
                <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1" x-text="`${storageUsed}%`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="storageText"></p>
            </div>
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">Uptime</p>
                <p class="text-2xl font-bold text-purple-600 dark:text-purple-400 mt-1" x-text="health?.uptime_human || '—'"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="`${health?.restart_attempts || 0} restarts`"></p>
            </div>
        </div>

        <!-- Charts -->
        <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
            <div class="flex gap-2 border-b border-slate-200 dark:border-slate-700 pb-4 mb-6">
                <button @click="loadMetrics(1)" :class="timeRange === 1 ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="px-4 py-2 border-b-2 font-medium transition text-sm">1h</button>
                <button @click="loadMetrics(6)" :class="timeRange === 6 ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="px-4 py-2 border-b-2 font-medium transition text-sm">6h</button>
                <button @click="loadMetrics(24)" :class="timeRange === 24 ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="px-4 py-2 border-b-2 font-medium transition text-sm">24h</button>
                <button @click="loadMetrics(168)" :class="timeRange === 168 ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="px-4 py-2 border-b-2 font-medium transition text-sm">7d</button>
            </div>

            <template x-if="loading && labels.length === 0">
                <div class="text-center py-12">
                    <div class="inline-flex items-center gap-2">
                        <div class="w-4 h-4 bg-blue-500 rounded-full animate-bounce"></div>
                        <span class="text-slate-600 dark:text-slate-400">Loading metrics…</span>
                    </div>
                </div>
            </template>

            <div x-show="!loading && labels.length === 0" class="text-center py-12">
                <span class="text-slate-600 dark:text-slate-400">No metrics available yet.</span>
            </div>

            <div x-show="labels.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">CPU usage (%)</h3>
                    <canvas id="cpuChart" class="max-h-64"></canvas>
                </div>
                <div>
                    <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">Memory usage (MB)</h3>
                    <canvas id="memoryChart" class="max-h-64"></canvas>
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-700">
                <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">Disk usage</h3>
                <div class="flex items-center gap-4">
                    <div class="flex-1">
                        <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2">
                            <div class="bg-green-500 h-2 rounded-full transition-all duration-300" :style="`width: ${storageUsed}%`"></div>
                        </div>
                    </div>
                    <div class="text-sm text-slate-600 dark:text-slate-400 whitespace-nowrap" x-text="storageText"></div>
                </div>
            </div>
        </div>

        <!-- Deployment + SSL -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Deployment</h3>
                    <button type="button" @click="$dispatch('container-set-tab', 'documentation')" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">Docs →</button>
                </div>
                <div class="space-y-3">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Node</p>
                        <div class="flex items-center gap-2 mt-1">
                            <div class="w-2 h-2 rounded-full" :class="health?.node?.status === 'online' ? 'bg-green-500' : 'bg-slate-400'"></div>
                            <p class="text-sm text-slate-900 dark:text-white font-mono" x-text="health?.node?.hostname || 'N/A'"></p>
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Access</p>
                        <p class="text-sm text-slate-900 dark:text-white font-mono mt-1" x-text="`${health?.node?.ip || 'N/A'}:${health?.assigned_port || 'N/A'}`"></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Image</p>
                        <p class="text-sm text-slate-900 dark:text-white font-mono mt-1" x-text="health?.selected_version || 'Latest'"></p>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white">Domains & SSL</h3>
                    <button type="button" @click="$dispatch('container-set-tab', 'domains')" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">Manage →</button>
                </div>
                <template x-if="health?.ssl_domains && health.ssl_domains.length > 0">
                    <div class="space-y-3">
                        <template x-for="domain in health.ssl_domains" :key="domain.domain">
                            <div class="p-3 bg-slate-50 dark:bg-slate-700/50 rounded border border-slate-200 dark:border-slate-600">
                                <p class="text-sm font-mono text-slate-900 dark:text-white" x-text="domain.domain"></p>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="px-2 py-0.5 text-xs font-medium rounded"
                                          :class="{
                                              'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300': domain.status === 'active',
                                              'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300': domain.status === 'pending',
                                              'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300': domain.status === 'failed'
                                          }"
                                          x-text="domain.status"></span>
                                    <span x-show="domain.ssl_enabled" class="text-xs text-green-600 dark:text-green-400">SSL active</span>
                                    <span x-show="!domain.ssl_enabled" class="text-xs text-amber-600 dark:text-amber-400">No SSL</span>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="!health?.ssl_domains || health.ssl_domains.length === 0">
                    <div class="text-center py-4 text-slate-600 dark:text-slate-400">
                        <p class="text-sm">No custom domains yet</p>
                        <p class="text-xs mt-1">Point A record to <code class="font-mono">{{ $deployment?->node?->ip_address }}</code></p>
                    </div>
                </template>
            </div>
        </div>

        <!-- Recent logs preview -->
        <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white">Recent logs</h3>
                <button type="button" @click="$dispatch('container-set-tab', 'logs')" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:underline">Full logs →</button>
            </div>
            <div class="flex gap-2 mb-3">
                <button type="button" @click="loadLogPreview()" :disabled="logsLoading" class="px-3 py-1.5 text-sm rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-600 disabled:opacity-50">
                    <span x-text="logsLoading ? 'Loading…' : 'Refresh'"></span>
                </button>
            </div>
            <pre class="bg-slate-900 text-slate-300 p-4 rounded-lg font-mono text-xs overflow-x-auto max-h-48 whitespace-pre-wrap" x-text="logPreview || 'Loading recent output…'"></pre>
        </div>

        <!-- Advanced metrics (collapsed by default) -->
        <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
            <button type="button" @click="showAdvanced = !showAdvanced" class="w-full flex items-center justify-between px-6 py-4 text-left hover:bg-slate-50 dark:hover:bg-slate-700/50 transition">
                <span class="font-semibold text-slate-900 dark:text-white">Advanced metrics & history</span>
                <span class="text-slate-500 dark:text-slate-400 text-sm" x-text="showAdvanced ? 'Hide' : 'Show'"></span>
            </button>

            <div x-show="showAdvanced" x-cloak class="px-6 pb-6 space-y-6 border-t border-slate-200 dark:border-slate-700 pt-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <div class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase">CPU avg</p>
                        <p class="text-xl font-bold text-blue-500 mt-1" x-text="`${summary?.cpu_avg || 0}%`"></p>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase">RAM avg</p>
                        <p class="text-xl font-bold text-green-500 mt-1" x-text="`${summary?.memory_avg || 0} MB`"></p>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase">Network</p>
                        <p class="text-sm font-semibold text-orange-600 mt-1" x-text="`↓ ${formatBytes(summary?.net_rx_total || 0)}`"></p>
                        <p class="text-sm font-semibold text-orange-500" x-text="`↑ ${formatBytes(summary?.net_tx_total || 0)}`"></p>
                    </div>
                    <div class="bg-slate-50 dark:bg-slate-900/50 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                        <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase">Activity</p>
                        <p class="text-sm font-semibold text-slate-900 dark:text-white mt-1" x-text="`~${formatBytes(health?.activity_rate_bytes_per_min || 0)}/min`"></p>
                    </div>
                </div>

                <div x-show="labels.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div>
                        <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">Network I/O</h3>
                        <canvas id="networkChart" class="max-h-64"></canvas>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">Disk I/O</h3>
                        <canvas id="diskChart" class="max-h-64"></canvas>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-4">Deployment timeline</h3>
                    <template x-if="health?.timeline && health.timeline.length > 0">
                        <div class="space-y-4">
                            <template x-for="(event, i) in health.timeline" :key="`${event.type}-${i}`">
                                <div class="flex gap-4">
                                    <div class="flex flex-col items-center">
                                        <div class="w-3 h-3 rounded-full"
                                             :class="{
                                                 'bg-green-500': event.type === 'deployed',
                                                 'bg-blue-500': event.type === 'migrated',
                                                 'bg-yellow-500': event.type === 'restart'
                                             }"></div>
                                        <template x-if="i < health.timeline.length - 1">
                                            <div class="w-0.5 h-6 bg-slate-200 dark:bg-slate-700"></div>
                                        </template>
                                    </div>
                                    <div class="pb-4">
                                        <p class="font-medium text-slate-900 dark:text-white text-sm" x-text="event.label"></p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1" x-text="event.human"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                    <template x-if="!health?.timeline || health.timeline.length === 0">
                        <p class="text-sm text-slate-600 dark:text-slate-400">No events yet</p>
                    </template>
                </div>

                <div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-4">Bandwidth analytics</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-700">
                                    <th class="text-left py-2 px-2 font-medium text-slate-600 dark:text-slate-400">Period</th>
                                    <th class="text-right py-2 px-2 font-medium text-slate-600 dark:text-slate-400">RX</th>
                                    <th class="text-right py-2 px-2 font-medium text-slate-600 dark:text-slate-400">TX</th>
                                    <th class="text-right py-2 px-2 font-medium text-slate-600 dark:text-slate-400">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="(bw, period) in health?.bandwidth || {}" :key="period">
                                    <tr class="border-b border-slate-200 dark:border-slate-700">
                                        <td class="py-3 px-2 text-slate-900 dark:text-white font-mono uppercase" x-text="period"></td>
                                        <td class="py-3 px-2 text-right text-slate-700 dark:text-slate-300" x-text="formatBytes(bw.rx)"></td>
                                        <td class="py-3 px-2 text-right text-slate-700 dark:text-slate-300" x-text="formatBytes(bw.tx)"></td>
                                        <td class="py-3 px-2 text-right text-slate-700 dark:text-slate-300 font-semibold" x-text="formatBytes(bw.rx + bw.tx)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h3 class="text-sm font-bold text-slate-900 dark:text-white mb-4">Resource allocation</h3>
                    <div class="space-y-4">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-sm font-medium text-slate-700 dark:text-slate-300">CPU</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400" x-text="`${summary?.cpu_percentage || 0}% of ${health?.allocation?.cpu_cores || {{ $containerLimits['cpu'] }}} cores`"></p>
                            </div>
                            <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2.5">
                                <div class="bg-blue-500 h-2.5 rounded-full transition-all duration-300" :style="`width: ${Math.min(100, Math.round((summary?.cpu_percentage || 0) / (health?.allocation?.cpu_cores || {{ $containerLimits['cpu'] }} || 1) * 100))}%`"></div>
                            </div>
                        </div>
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Memory</p>
                                <p class="text-sm text-slate-600 dark:text-slate-400" x-text="`${summary?.memory_used_mb || 0}MB of ${health?.allocation?.memory_mb || {{ $containerLimits['memory_mb'] }}}MB`"></p>
                            </div>
                            <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2.5">
                                <div class="bg-green-500 h-2.5 rounded-full transition-all duration-300" :style="`width: ${Math.min(100, Math.round((summary?.memory_used_mb || 0) / (health?.allocation?.memory_mb || {{ $containerLimits['memory_mb'] }} || 1) * 100))}%`"></div>
                            </div>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Contact support to adjust plan limits.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function containerDashboard() {
    return {
        timeRange: 24,
        loading: false,
        healthLoading: false,
        autoRefresh: false,
        refreshTimer: null,
        showAdvanced: false,
        summary: null,
        health: null,
        labels: [],
        cpu: [],
        memory: [],
        netRx: [],
        netTx: [],
        diskRead: [],
        diskWrite: [],
        memoryLimit: 0,
        storageUsed: 0,
        storageText: 'Loading…',
        diskLimitGb: {{ $containerLimits['disk_gb'] }},
        logPreview: '',
        logsLoading: false,
        charts: {},

        init() {
            this.loadMetrics(24);
            this.loadHealth();
            this.loadStorageStats();
            this.loadLogPreview();

            if ('{{ $deployment?->status }}' === 'running') {
                this.toggleAutoRefresh();
            }

            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.pauseRefresh();
                } else if (this.autoRefresh && !this.refreshTimer) {
                    this.startRefreshTimer();
                }
            });

            this.$watch('showAdvanced', (open) => {
                if (open) {
                    this.$nextTick(() => this.initCharts(true));
                }
            });
        },

        toggleAutoRefresh() {
            this.autoRefresh = !this.autoRefresh;
            if (this.autoRefresh) {
                this.startRefreshTimer();
            } else {
                this.pauseRefresh();
            }
        },

        startRefreshTimer() {
            this.pauseRefresh();
            this.refreshTimer = setInterval(() => {
                if (document.hidden) return;
                this.loadMetrics(this.timeRange);
                this.loadHealth();
            }, 30000);
        },

        pauseRefresh() {
            if (this.refreshTimer) {
                clearInterval(this.refreshTimer);
                this.refreshTimer = null;
            }
        },

        async loadMetrics(hours) {
            this.timeRange = hours;
            this.loading = true;

            try {
                const response = await fetch(`{{ route('customer.services.container.metrics', $service->id) }}?hours=${hours}`, {
                    headers: { 'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content }
                });

                if (!response.ok) throw new Error('Failed to load metrics');

                const data = await response.json();
                this.labels = data.labels;
                this.cpu = data.cpu;
                this.memory = data.memory;
                this.netRx = data.net_rx;
                this.netTx = data.net_tx;
                this.diskRead = data.disk_read;
                this.diskWrite = data.disk_write;
                this.summary = data.summary;
                this.memoryLimit = data.summary?.memory_limit_mb || 0;

                this.$nextTick(() => this.initCharts(false));
            } catch (error) {
                console.error('Error loading metrics:', error);
            } finally {
                this.loading = false;
            }
        },

        async loadHealth() {
            this.healthLoading = true;

            try {
                const response = await fetch(`{{ route('customer.services.container.health', $service->id) }}`, {
                    headers: { 'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content }
                });

                if (!response.ok) throw new Error('Failed to load health');

                this.health = await response.json();
            } catch (error) {
                console.error('Error loading health:', error);
            } finally {
                this.healthLoading = false;
            }
        },

        async loadStorageStats() {
            try {
                const response = await fetch(`{{ route('customer.services.container.storage-stats', $service->id) }}`, {
                    headers: { 'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content }
                });

                if (!response.ok) throw new Error('Failed to load storage stats');

                const data = await response.json();
                const limitBytes = this.diskLimitGb * 1024 * 1024 * 1024;
                this.storageUsed = limitBytes > 0 ? Math.min(100, Math.round(data.used_bytes / limitBytes * 100)) : 0;
                this.storageText = `${data.human} / ${this.diskLimitGb} GB`;
            } catch (error) {
                console.error('Error loading storage stats:', error);
                this.storageText = 'Unavailable';
            }
        },

        async loadLogPreview() {
            this.logsLoading = true;

            try {
                const response = await fetch(`{{ route('customer.services.container.logs', $service) }}`);
                const data = await response.json();

                if (data.error) {
                    this.logPreview = `Error: ${data.error}`;
                } else {
                    const lines = (data.logs || 'No logs available').split('\n');
                    this.logPreview = lines.slice(-20).join('\n');
                }
            } catch (error) {
                this.logPreview = 'Failed to load logs';
            } finally {
                this.logsLoading = false;
            }
        },

        initCharts(includeAdvanced = false) {
            const cpuEl = document.getElementById('cpuChart');
            const memoryEl = document.getElementById('memoryChart');
            const networkEl = document.getElementById('networkChart');
            const diskEl = document.getElementById('diskChart');

            if (!cpuEl || !memoryEl) return;

            Object.values(this.charts).forEach(chart => {
                if (chart instanceof Chart) chart.destroy();
            });
            this.charts = {};

            const isDark = document.documentElement.classList.contains('dark');
            const textColor = isDark ? '#cbd5e1' : '#475569';
            const gridColor = isDark ? '#334155' : '#e2e8f0';

            const chartOptions = {
                responsive: true,
                maintainAspectRatio: true,
                animation: false,
                plugins: { legend: { display: false }, filler: { propagate: true } },
                scales: {
                    y: { beginAtZero: true, ticks: { color: textColor }, grid: { color: gridColor } },
                    x: { ticks: { color: textColor }, grid: { color: gridColor } }
                }
            };

            this.charts.cpu = new Chart(cpuEl, {
                type: 'line',
                data: {
                    labels: this.labels,
                    datasets: [{
                        label: 'CPU %',
                        data: this.cpu,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: { ...chartOptions, scales: { ...chartOptions.scales, y: { ...chartOptions.scales.y, max: 100 } } }
            });

            this.charts.memory = new Chart(memoryEl, {
                type: 'line',
                data: {
                    labels: this.labels,
                    datasets: [
                        {
                            label: 'Memory Used',
                            data: this.memory,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.3,
                            fill: true
                        },
                        {
                            label: 'Memory Limit',
                            data: Array(this.labels.length).fill(this.memoryLimit),
                            borderColor: '#ef4444',
                            borderDash: [5, 5],
                            borderWidth: 2,
                            fill: false
                        }
                    ]
                },
                options: chartOptions
            });

            if (includeAdvanced && networkEl && diskEl) {
                this.charts.network = new Chart(networkEl, {
                    type: 'line',
                    data: {
                        labels: this.labels,
                        datasets: [
                            { label: 'RX', data: this.netRx, borderColor: '#3b82f6', backgroundColor: 'rgba(59, 130, 246, 0.1)', tension: 0.3, fill: true },
                            { label: 'TX', data: this.netTx, borderColor: '#f97316', backgroundColor: 'rgba(249, 115, 22, 0.1)', tension: 0.3, fill: true }
                        ]
                    },
                    options: chartOptions
                });

                this.charts.disk = new Chart(diskEl, {
                    type: 'line',
                    data: {
                        labels: this.labels,
                        datasets: [
                            { label: 'Read', data: this.diskRead, borderColor: '#a855f7', backgroundColor: 'rgba(168, 85, 247, 0.1)', tension: 0.3, fill: true },
                            { label: 'Write', data: this.diskWrite, borderColor: '#ef4444', backgroundColor: 'rgba(239, 68, 68, 0.1)', tension: 0.3, fill: true }
                        ]
                    },
                    options: chartOptions
                });
            }
        },

        formatBytes(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            bytes = Math.max(bytes, 0);
            const pow = Math.floor((bytes ? Math.log(bytes) : 0) / Math.log(1024));
            const index = Math.min(pow, units.length - 1);
            bytes /= Math.pow(1024, index);
            return Math.round(bytes * 100) / 100 + ' ' + units[index];
        }
    }
}
</script>
@endpush
