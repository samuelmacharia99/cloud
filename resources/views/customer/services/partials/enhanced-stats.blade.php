<div class="space-y-6">
    <div x-data="containerDashboard()" x-init="init()" class="space-y-6">
        <!-- Section A: Health Banner -->
        <div class="px-6 py-4 rounded-lg border-l-4 transition-all"
             :class="health?.health_score >= 80 ? 'bg-green-50 dark:bg-green-900/20 border-green-500' :
                     health?.health_score >= 50 ? 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-500' :
                     'bg-red-50 dark:bg-red-900/20 border-red-500'">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="relative flex h-3 w-3">
                        <span v-if="health?.status === 'running'" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                        <span :class="{
                            'bg-green-500': health?.status === 'running',
                            'bg-yellow-400': health?.status === 'stopped',
                            'bg-blue-500': health?.status === 'deploying',
                            'bg-red-500': health?.status === 'failed'
                        }" class="relative inline-flex rounded-full h-3 w-3"></span>
                    </div>
                    <div>
                        <h3 class="font-semibold text-slate-900 dark:text-white" x-text="`${health?.status?.toUpperCase()} — Health: ${health?.health_score}%`"></h3>
                        <p class="text-xs text-slate-600 dark:text-slate-400" x-text="`Last checked: ${health?.last_check_human}`"></p>
                    </div>
                </div>
                <button @click="toggleAutoRefresh()" :class="autoRefresh ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400'"
                        class="px-3 py-1.5 rounded text-sm font-medium transition">
                    <span x-show="autoRefresh" class="inline-block w-2 h-2 bg-current rounded-full mr-1.5 animate-pulse"></span>
                    <span x-text="autoRefresh ? 'Auto-refresh: ON' : 'Auto-refresh: OFF'"></span>
                </button>
            </div>
        </div>

        <!-- Section B: Incident Alert (conditional) -->
        <template x-if="health?.incident_level !== 'none'">
            <div :class="health?.incident_level === 'critical' ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800' : 'bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800'"
                 class="px-6 py-4 rounded-lg border flex items-start gap-4">
                <div :class="health?.incident_level === 'critical' ? 'text-red-600 dark:text-red-400' : 'text-yellow-600 dark:text-yellow-400'" class="text-xl flex-shrink-0">
                    <span x-text="health?.incident_level === 'critical' ? '🔴' : '⚠️'"></span>
                </div>
                <div>
                    <h4 :class="health?.incident_level === 'critical' ? 'text-red-800 dark:text-red-200' : 'text-yellow-800 dark:text-yellow-200'" class="font-semibold" x-text="health?.incident_level === 'critical' ? 'Critical Issue' : 'Warning'"></h4>
                    <p :class="health?.incident_level === 'critical' ? 'text-red-700 dark:text-red-300' : 'text-yellow-700 dark:text-yellow-300'" class="text-sm mt-1" x-text="health?.incident_message"></p>
                </div>
            </div>
        </template>

        <!-- Section C: KPI Cards (8 cards) -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <!-- CPU Now -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">CPU Now</p>
                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1" x-text="`${summary?.cpu_percentage || 0}%`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="`Peak: ${summary?.cpu_peak || 0}%`"></p>
            </div>

            <!-- RAM Now -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">RAM Now</p>
                <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-1" x-text="`${summary?.memory_used_mb || 0} MB`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="`${Math.round((summary?.memory_used_mb || 0) / (summary?.memory_limit_mb || 1) * 100)}% used`"></p>
            </div>

            <!-- CPU Avg -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">CPU Avg</p>
                <p class="text-2xl font-bold text-blue-500 dark:text-blue-300 mt-1" x-text="`${summary?.cpu_avg || 0}%`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="`Last ${timeRange}h`"></p>
            </div>

            <!-- RAM Avg -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">RAM Avg</p>
                <p class="text-2xl font-bold text-green-500 dark:text-green-300 mt-1" x-text="`${summary?.memory_avg || 0} MB`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="`Last ${timeRange}h`"></p>
            </div>

            <!-- Net RX -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">RX Total</p>
                <p class="text-2xl font-bold text-orange-600 dark:text-orange-400 mt-1" x-text="`${formatBytes(summary?.net_rx_total || 0)}`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1">Downloaded</p>
            </div>

            <!-- Net TX -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">TX Total</p>
                <p class="text-2xl font-bold text-orange-500 dark:text-orange-300 mt-1" x-text="`${formatBytes(summary?.net_tx_total || 0)}`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1">Uploaded</p>
            </div>

            <!-- Restarts -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">Restarts</p>
                <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1" x-text="health?.restart_attempts || 0"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="health?.last_restart_human ? `${health?.last_restart_human}` : 'Never'"></p>
            </div>

            <!-- Uptime % -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-4 border border-slate-200 dark:border-slate-700">
                <p class="text-xs font-medium text-slate-600 dark:text-slate-400 uppercase tracking-wide">Uptime %</p>
                <p class="text-2xl font-bold text-purple-600 dark:text-purple-400 mt-1" x-text="`${Math.min(100, Math.round((health?.uptime_seconds || 0) / (Math.max(1, (Date.now() / 1000) - (health?.deployed_at_ts || 0))) * 100))}%`"></p>
                <p class="text-xs text-slate-500 dark:text-slate-500 mt-1" x-text="health?.uptime_human"></p>
            </div>
        </div>

        <!-- Section D: Time Range Selector + Live Charts -->
        <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
            <!-- Time range tabs -->
            <div class="flex gap-2 border-b border-slate-200 dark:border-slate-700 pb-4 mb-6">
                <button @click="loadMetrics(1)" :class="timeRange === 1 ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="px-4 py-2 border-b-2 font-medium transition text-sm">1h</button>
                <button @click="loadMetrics(6)" :class="timeRange === 6 ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="px-4 py-2 border-b-2 font-medium transition text-sm">6h</button>
                <button @click="loadMetrics(24)" :class="timeRange === 24 ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="px-4 py-2 border-b-2 font-medium transition text-sm">24h</button>
                <button @click="loadMetrics(168)" :class="timeRange === 168 ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'text-slate-600 dark:text-slate-400 border-slate-300 dark:border-slate-700'" class="px-4 py-2 border-b-2 font-medium transition text-sm">7d</button>
            </div>

            <!-- Charts loading (initial load only; refreshes keep showing prior charts) -->
            <template x-if="loading && labels.length === 0">
                <div class="text-center py-12">
                    <div class="inline-flex items-center gap-2">
                        <div class="w-4 h-4 bg-blue-500 rounded-full animate-bounce"></div>
                        <span class="text-slate-600 dark:text-slate-400">Loading metrics...</span>
                    </div>
                </div>
            </template>

            <!-- No data state -->
            <div x-show="!loading && labels.length === 0" class="text-center py-12">
                <span class="text-slate-600 dark:text-slate-400">No metrics available yet.</span>
            </div>

            <!-- Charts grid (kept in DOM via x-show so Chart.js canvases are never torn out) -->
            <div x-show="labels.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- CPU Chart -->
                <div>
                    <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">CPU Usage (%)</h3>
                    <canvas id="cpuChart" class="max-h-64"></canvas>
                </div>

                <!-- Memory Chart -->
                <div>
                    <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">Memory Usage (MB)</h3>
                    <canvas id="memoryChart" class="max-h-64"></canvas>
                </div>

                <!-- Network I/O Chart -->
                <div>
                    <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">Network I/O (Bytes)</h3>
                    <canvas id="networkChart" class="max-h-64"></canvas>
                </div>

                <!-- Disk I/O Chart -->
                <div>
                    <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">Disk I/O (Bytes)</h3>
                    <canvas id="diskChart" class="max-h-64"></canvas>
                </div>
            </div>

            <!-- Storage Usage -->
            <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-700">
                <h3 class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-4">Storage Usage</h3>
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

        <!-- Section E: Infrastructure + SSL (2-column) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Left: Deployment Details -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Deployment Details</h3>
                <div class="space-y-3">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Node</p>
                        <div class="flex items-center gap-2 mt-1">
                            <div class="w-2 h-2 rounded-full" :class="health?.node?.status === 'online' ? 'bg-green-500' : 'bg-slate-400'"></div>
                            <p class="text-sm text-slate-900 dark:text-white font-mono" x-text="health?.node?.hostname || 'N/A'"></p>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1" x-text="`${health?.node?.region || 'N/A'} — ${health?.node?.datacenter || 'N/A'}`"></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Container</p>
                        <p class="text-sm text-slate-900 dark:text-white font-mono mt-1" x-text="health?.container_name || 'N/A'"></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Port</p>
                        <p class="text-sm text-slate-900 dark:text-white font-mono mt-1" x-text="`${health?.node?.ip || 'N/A'}:${health?.assigned_port || 'N/A'}`"></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Image Version</p>
                        <p class="text-sm text-slate-900 dark:text-white font-mono mt-1" x-text="health?.selected_version || 'Latest'"></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide">Restart Policy</p>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="px-2 py-1 text-xs font-medium bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-300 rounded" x-text="health?.restart_policy || 'unless-stopped'"></span>
                            <span x-show="health?.auto_restart" class="text-xs text-green-600 dark:text-green-400">Auto-restart enabled</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right: SSL & Domains -->
            <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">SSL & Domains</h3>
                <template x-if="health?.ssl_domains && health?.ssl_domains.length > 0">
                    <div class="space-y-3">
                        <template x-for="domain in health?.ssl_domains" :key="domain.domain">
                            <div class="p-3 bg-slate-50 dark:bg-slate-700/50 rounded border border-slate-200 dark:border-slate-600">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <p class="text-sm font-mono text-slate-900 dark:text-white" x-text="domain.domain"></p>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="px-2 py-1 text-xs font-medium rounded"
                                                  :class="{
                                                      'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300': domain.status === 'active',
                                                      'bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300': domain.status === 'pending',
                                                      'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300': domain.status === 'failed'
                                                  }"
                                                  x-text="domain.status">
                                            </span>
                                            <span v-if="domain.ssl_enabled" class="flex items-center gap-1 text-xs text-green-600 dark:text-green-400">
                                                🔒 SSL Active
                                            </span>
                                            <span v-else class="flex items-center gap-1 text-xs text-yellow-600 dark:text-yellow-400">
                                                ⚠️ No SSL
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="!health?.ssl_domains || health?.ssl_domains.length === 0">
                    <div class="text-center py-6 text-slate-600 dark:text-slate-400">
                        <p class="text-sm">No custom domains configured</p>
                        <p class="text-xs mt-1">Point A record to <code class="font-mono">{{ $deployment?->node?->ip_address }}</code></p>
                    </div>
                </template>
            </div>
        </div>

        <!-- Section F: Deployment Timeline -->
        <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-6">Deployment Timeline</h3>
            <template x-if="health?.timeline && health?.timeline.length > 0">
                <div class="space-y-4">
                    <template x-for="(event, i) in health?.timeline" :key="`${event.type}-${i}`">
                        <div class="flex gap-4">
                            <div class="flex flex-col items-center">
                                <div class="w-3 h-3 rounded-full"
                                     :class="{
                                         'bg-green-500': event.type === 'deployed',
                                         'bg-blue-500': event.type === 'migrated',
                                         'bg-yellow-500': event.type === 'restart'
                                     }">
                                </div>
                                <div v-if="i < health?.timeline.length - 1" class="w-0.5 h-6 bg-slate-200 dark:bg-slate-700"></div>
                            </div>
                            <div class="pb-4">
                                <p class="font-medium text-slate-900 dark:text-white text-sm" x-text="event.label"></p>
                                <p class="text-xs text-slate-600 dark:text-slate-400 mt-1" x-text="event.human"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
            <template x-if="!health?.timeline || health?.timeline.length === 0">
                <p class="text-center text-slate-600 dark:text-slate-400 text-sm">No events yet</p>
            </template>
        </div>

        <!-- Section G: Bandwidth Analytics -->
        <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Bandwidth Analytics</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 dark:border-slate-700">
                            <th class="text-left py-2 px-2 font-medium text-slate-600 dark:text-slate-400">Period</th>
                            <th class="text-right py-2 px-2 font-medium text-slate-600 dark:text-slate-400">RX</th>
                            <th class="text-right py-2 px-2 font-medium text-slate-600 dark:text-slate-400">TX</th>
                            <th class="text-right py-2 px-2 font-medium text-slate-600 dark:text-slate-400">Peak</th>
                            <th class="text-right py-2 px-2 font-medium text-slate-600 dark:text-slate-400">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(bw, period) in health?.bandwidth" :key="period">
                            <tr class="border-b border-slate-200 dark:border-slate-700">
                                <td class="py-3 px-2 text-slate-900 dark:text-white font-mono" x-text="period.toUpperCase()"></td>
                                <td class="py-3 px-2 text-right text-slate-700 dark:text-slate-300" x-text="formatBytes(bw.rx)"></td>
                                <td class="py-3 px-2 text-right text-slate-700 dark:text-slate-300" x-text="formatBytes(bw.tx)"></td>
                                <td class="py-3 px-2 text-right text-slate-700 dark:text-slate-300" x-text="formatBytes(bw.peak)"></td>
                                <td class="py-3 px-2 text-right text-slate-700 dark:text-slate-300 font-semibold" x-text="formatBytes(bw.rx + bw.tx)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section H: Resource Allocation -->
        <div class="bg-white dark:bg-slate-800 rounded-lg p-6 border border-slate-200 dark:border-slate-700">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-6">Resource Allocation</h3>
            <div class="space-y-6">
                <!-- CPU Gauge -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-300">CPU</p>
                        <p class="text-sm text-slate-600 dark:text-slate-400" x-text="`${Math.round((summary?.cpu_percentage || 0) / (health?.allocation?.cpu_cores || 1) * 100)}% of ${health?.allocation?.cpu_cores || 1} cores`"></p>
                    </div>
                    <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2.5">
                        <div class="bg-blue-500 h-2.5 rounded-full transition-all duration-300" :style="`width: ${Math.min(100, Math.round((summary?.cpu_percentage || 0) / (health?.allocation?.cpu_cores || 1) * 100))}%`"></div>
                    </div>
                </div>

                <!-- RAM Gauge -->
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-300">Memory</p>
                        <p class="text-sm text-slate-600 dark:text-slate-400" x-text="`${summary?.memory_used_mb || 0}MB of ${health?.allocation?.memory_mb || 0}MB`"></p>
                    </div>
                    <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2.5">
                        <div class="bg-green-500 h-2.5 rounded-full transition-all duration-300" :style="`width: ${Math.min(100, Math.round((summary?.memory_used_mb || 0) / (health?.allocation?.memory_mb || 1) * 100))}%`"></div>
                    </div>
                </div>

                <!-- Network Activity -->
                <div>
                    <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Network Activity</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400" x-text="`~${formatBytes(health?.activity_rate_bytes_per_min || 0)}/min`"></p>
                </div>

                <!-- Scaling Info -->
                <div class="bg-slate-50 dark:bg-slate-700/50 rounded p-4 border border-slate-200 dark:border-slate-600">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="text-xs font-semibold bg-slate-200 dark:bg-slate-600 text-slate-700 dark:text-slate-300 px-2 py-1 rounded">Manual Scaling</span>
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400">Current allocation: {{ $deployment?->cpu_limit ?? $service->product->containerTemplate->required_cpu_cores }} CPU, {{ $deployment?->memory_limit_mb ?? $service->product->containerTemplate->required_ram_mb }}MB RAM</p>
                    <p class="text-xs text-slate-500 dark:text-slate-500 mt-2">Contact support to adjust resource limits</p>
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
        storageText: 'Loading...',
        charts: {},

        init() {
            this.loadMetrics(24);
            this.loadHealth();
            this.loadStorageStats();

            // Auto-start refresh if running
            if ('{{ $deployment?->status }}' === 'running') {
                this.toggleAutoRefresh();
            }
        },

        toggleAutoRefresh() {
            this.autoRefresh = !this.autoRefresh;
            if (this.autoRefresh) {
                this.refreshTimer = setInterval(() => {
                    this.loadMetrics(this.timeRange);
                    this.loadHealth();
                }, 30000);
            } else {
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

                this.$nextTick(() => this.initCharts());
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
                const limitBytes = 100 * 1024 * 1024 * 1024;
                this.storageUsed = Math.min(100, Math.round(data.used_bytes / limitBytes * 100));
                this.storageText = `${data.human} / 100 GB`;
            } catch (error) {
                console.error('Error loading storage stats:', error);
            }
        },

        initCharts() {
            const cpuEl = document.getElementById('cpuChart');
            const memoryEl = document.getElementById('memoryChart');
            const networkEl = document.getElementById('networkChart');
            const diskEl = document.getElementById('diskChart');

            // Canvases live behind x-show; bail if they aren't rendered yet
            if (!cpuEl || !memoryEl || !networkEl || !diskEl) return;

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
                // Disable animation: its requestAnimationFrame loop keeps drawing
                // after tab switches hide the canvas, crashing on a null context
                animation: false,
                plugins: {
                    legend: { display: false },
                    filler: { propagate: true }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { color: textColor }, grid: { color: gridColor } },
                    x: { ticks: { color: textColor }, grid: { color: gridColor } }
                }
            };

            // CPU Chart
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

            // Memory Chart
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

            // Network Chart
            this.charts.network = new Chart(networkEl, {
                type: 'line',
                data: {
                    labels: this.labels,
                    datasets: [
                        {
                            label: 'RX',
                            data: this.netRx,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.3,
                            fill: true
                        },
                        {
                            label: 'TX',
                            data: this.netTx,
                            borderColor: '#f97316',
                            backgroundColor: 'rgba(249, 115, 22, 0.1)',
                            tension: 0.3,
                            fill: true
                        }
                    ]
                },
                options: chartOptions
            });

            // Disk Chart
            this.charts.disk = new Chart(diskEl, {
                type: 'line',
                data: {
                    labels: this.labels,
                    datasets: [
                        {
                            label: 'Read',
                            data: this.diskRead,
                            borderColor: '#a855f7',
                            backgroundColor: 'rgba(168, 85, 247, 0.1)',
                            tension: 0.3,
                            fill: true
                        },
                        {
                            label: 'Write',
                            data: this.diskWrite,
                            borderColor: '#ef4444',
                            backgroundColor: 'rgba(239, 68, 68, 0.1)',
                            tension: 0.3,
                            fill: true
                        }
                    ]
                },
                options: chartOptions
            });
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
