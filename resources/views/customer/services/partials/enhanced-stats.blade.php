<div class="bg-white rounded-lg shadow p-6 mb-8">
    <h3 class="text-lg font-bold mb-6">Resource Usage & Metrics</h3>

<div x-data="containerStats()" x-init="init()" class="space-y-6">
    <!-- Time range tabs -->
    <div class="flex gap-2 border-b border-gray-200">
        <button @click="loadMetrics(1)" :class="timeRange === 1 ? 'border-blue-500 text-blue-600' : 'text-gray-600'" class="px-4 py-2 border-b-2 transition">1h</button>
        <button @click="loadMetrics(6)" :class="timeRange === 6 ? 'border-blue-500 text-blue-600' : 'text-gray-600'" class="px-4 py-2 border-b-2 transition">6h</button>
        <button @click="loadMetrics(24)" :class="timeRange === 24 ? 'border-blue-500 text-blue-600' : 'text-gray-600'" class="px-4 py-2 border-b-2 transition">24h</button>
        <button @click="loadMetrics(168)" :class="timeRange === 168 ? 'border-blue-500 text-blue-600' : 'text-gray-600'" class="px-4 py-2 border-b-2 transition">7d</button>
    </div>

    <!-- Summary stat cards -->
    <template x-if="summary">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <!-- CPU Usage -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-sm font-medium text-gray-600">CPU Usage</div>
                <div class="text-2xl font-bold text-blue-600 mt-1" x-text="`${summary.cpu_avg}%`"></div>
                <div class="text-xs text-gray-500 mt-2">Average</div>
            </div>

            <!-- Memory Usage -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-sm font-medium text-gray-600">Memory Usage</div>
                <div class="text-2xl font-bold text-green-600 mt-1">
                    <span x-text="`${summary.memory_avg} / ${summary.memory_limit_mb}`"></span> MB
                </div>
                <div class="text-xs text-gray-500 mt-2" x-text="`${Math.round(summary.memory_avg / summary.memory_limit_mb * 100)}% used`"></div>
            </div>

            <!-- Network I/O -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-sm font-medium text-gray-600">Network I/O</div>
                <div class="text-2xl font-bold text-orange-600 mt-1" x-text="`${formatBytes(summary.net_rx_total + summary.net_tx_total)}`"></div>
                <div class="text-xs text-gray-500 mt-2">Total transferred</div>
            </div>

            <!-- Uptime -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <div class="text-sm font-medium text-gray-600">Uptime</div>
                <div class="text-2xl font-bold text-purple-600 mt-1" x-text="summary.uptime_human"></div>
                <div class="text-xs text-gray-500 mt-2">Since deployment</div>
            </div>
        </div>
    </template>

    <!-- Charts loading -->
    <template x-if="loading">
        <div class="text-center py-12">
            <div class="inline-flex items-center gap-2">
                <div class="w-4 h-4 bg-blue-500 rounded-full animate-bounce"></div>
                <span class="text-gray-600">Loading metrics...</span>
            </div>
        </div>
    </template>

    <!-- Charts -->
    <template x-if="!loading && labels.length > 0">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- CPU Chart -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-700 mb-4">CPU Usage (%)</h3>
                <canvas id="cpuChart" class="max-h-64"></canvas>
            </div>

            <!-- Memory Chart -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-700 mb-4">Memory Usage (MB)</h3>
                <canvas id="memoryChart" class="max-h-64"></canvas>
            </div>

            <!-- Network I/O Chart -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-700 mb-4">Network I/O (Bytes)</h3>
                <canvas id="networkChart" class="max-h-64"></canvas>
            </div>

            <!-- Disk I/O Chart -->
            <div class="bg-white border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-medium text-gray-700 mb-4">Disk I/O (Bytes)</h3>
                <canvas id="diskChart" class="max-h-64"></canvas>
            </div>
        </div>
    </template>

    <!-- Storage Usage -->
    <div class="bg-white border border-gray-200 rounded-lg p-4">
        <h3 class="text-sm font-medium text-gray-700 mb-4">Storage Usage</h3>
        <div class="flex items-center gap-4">
            <div class="flex-1">
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-green-500 h-2 rounded-full transition-all duration-300" :style="`width: ${storageUsed}%`"></div>
                </div>
            </div>
            <div class="text-sm text-gray-600" x-text="storageText"></div>
        </div>
    </div>
</div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
function containerStats() {
    return {
        timeRange: 24,
        loading: false,
        summary: null,
        labels: [],
        cpu: [],
        memory: [],
        memoryLimit: 0,
        netRx: [],
        netTx: [],
        diskRead: [],
        diskWrite: [],
        storageUsed: 0,
        storageText: 'Loading...',
        charts: {},

        init() {
            this.loadMetrics(24);
            this.loadStorageStats();
            // Refresh storage stats every 5 minutes
            setInterval(() => this.loadStorageStats(), 300000);
        },

        async loadMetrics(hours) {
            this.timeRange = hours;
            this.loading = true;

            try {
                const response = await fetch(`{{ route('customer.services.container.metrics', $service->id) }}?hours=${hours}`, {
                    headers: {
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content
                    }
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

        async loadStorageStats() {
            try {
                const response = await fetch(`{{ route('customer.services.container.storage-stats', $service->id) }}`, {
                    headers: {
                        'X-CSRF-TOKEN': document.head.querySelector('meta[name="csrf-token"]').content
                    }
                });

                if (!response.ok) throw new Error('Failed to load storage stats');

                const data = await response.json();
                // Assume 100GB limit for now
                const limitBytes = 100 * 1024 * 1024 * 1024;
                this.storageUsed = Math.min(100, Math.round(data.used_bytes / limitBytes * 100));
                this.storageText = `${data.human} / 100 GB`;
            } catch (error) {
                console.error('Error loading storage stats:', error);
            }
        },

        initCharts() {
            // Destroy existing charts
            Object.values(this.charts).forEach(chart => {
                if (chart instanceof Chart) chart.destroy();
            });
            this.charts = {};

            const chartOptions = {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            };

            // CPU Chart
            this.charts.cpu = new Chart(document.getElementById('cpuChart'), {
                type: 'line',
                data: {
                    labels: this.labels,
                    datasets: [{
                        label: 'CPU %',
                        data: this.cpu,
                        borderColor: '#fbbf24',
                        backgroundColor: 'rgba(251, 191, 36, 0.1)',
                        tension: 0.3,
                        fill: true
                    }]
                },
                options: { ...chartOptions, scales: { ...chartOptions.scales, y: { ...chartOptions.scales.y, max: 100 } } }
            });

            // Memory Chart
            this.charts.memory = new Chart(document.getElementById('memoryChart'), {
                type: 'line',
                data: {
                    labels: this.labels,
                    datasets: [
                        {
                            label: 'Memory Used',
                            data: this.memory,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
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
            this.charts.network = new Chart(document.getElementById('networkChart'), {
                type: 'line',
                data: {
                    labels: this.labels,
                    datasets: [
                        {
                            label: 'RX',
                            data: this.netRx,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
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
            this.charts.disk = new Chart(document.getElementById('diskChart'), {
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
