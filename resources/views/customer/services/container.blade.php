@extends('layouts.app')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-8">
        <div>
            <h1 class="text-3xl font-bold">{{ $service->name }}</h1>
            <p class="text-gray-600">{{ $service->product->containerTemplate->name ?? 'Container Service' }}</p>
        </div>
        <a href="{{ route('customer.services.index') }}" class="text-blue-600 hover:text-blue-700">← Back to Services</a>
    </div>

    @if ($message = Session::get('success'))
        <div class="mb-4 p-4 bg-green-100 border border-green-400 text-green-700 rounded">
            {{ $message }}
        </div>
    @endif

    @if ($message = Session::get('error'))
        <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
            {{ $message }}
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-4 bg-red-100 border border-red-400 text-red-700 rounded">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <!-- Status Card -->
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 mb-2">Status</p>
            @php
                $statusColor = match($deployment?->status) {
                    'running' => 'bg-green-100 text-green-800',
                    'stopped' => 'bg-yellow-100 text-yellow-800',
                    'deploying' => 'bg-blue-100 text-blue-800',
                    'failed' => 'bg-red-100 text-red-800',
                    default => 'bg-gray-100 text-gray-800'
                };
            @endphp
            <span class="px-3 py-1 rounded-full text-sm font-semibold {{ $statusColor }}">
                {{ ucfirst($deployment?->status ?? 'Pending') }}
            </span>
        </div>

        <!-- Resource Allocation -->
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 mb-2">Resource Allocation</p>
            <div class="space-y-2">
                <div class="flex justify-between">
                    <span class="text-sm">CPU:</span>
                    <span class="font-semibold">{{ $service->product->containerTemplate->required_cpu_cores }} cores</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm">RAM:</span>
                    <span class="font-semibold">{{ $service->product->containerTemplate->required_ram_mb }}MB</span>
                </div>
                <div class="flex justify-between">
                    <span class="text-sm">Storage:</span>
                    <span class="font-semibold">{{ $service->product->containerTemplate->required_storage_gb }}GB</span>
                </div>
            </div>
        </div>

        <!-- Uptime -->
        <div class="bg-white rounded-lg shadow p-6">
            <p class="text-sm text-gray-500 mb-2">Uptime</p>
            @if ($deployment?->deployed_at)
                <p class="font-semibold">{{ $deployment->deployed_at->diffForHumans() }}</p>
                <p class="text-sm text-gray-600">
                    {{ $deployment->deployed_at->format('M d, Y H:i') }}
                </p>
            @else
                <p class="text-gray-400">Not yet deployed</p>
            @endif
        </div>
    </div>

    @if ($deployment)
        <!-- Access Panel -->
        @php
            $credentials = json_decode($service->credentials ?? '{}', true);
        @endphp

        <div class="bg-white rounded-lg shadow p-6 mb-8">
            <h3 class="text-lg font-bold mb-4">Access Credentials</h3>

            @if ($deployment->getAccessUrl())
                <div class="mb-4">
                    <p class="text-sm text-gray-500 mb-1">Access URL</p>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 bg-gray-100 px-3 py-2 rounded font-mono text-sm overflow-x-auto">{{ $deployment->getAccessUrl() }}</code>
                        <a href="{{ $deployment->getAccessUrl() }}" target="_blank" class="px-3 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 text-sm">
                            Visit
                        </a>
                    </div>
                </div>
            @endif

            @if ($credentials)
                <div class="border-t pt-4">
                    @if ($credentials['admin_username'] ?? false)
                        <div class="mb-3">
                            <p class="text-sm text-gray-500 mb-1">Admin Username</p>
                            <code class="bg-gray-100 px-3 py-2 rounded font-mono text-sm">{{ $credentials['admin_username'] }}</code>
                        </div>
                    @endif

                    @if ($credentials['admin_email'] ?? false)
                        <div class="mb-3">
                            <p class="text-sm text-gray-500 mb-1">Admin Email</p>
                            <code class="bg-gray-100 px-3 py-2 rounded font-mono text-sm">{{ $credentials['admin_email'] }}</code>
                        </div>
                    @endif

                    <p class="text-sm text-gray-500 text-center mt-4">
                        Additional credentials were provided during setup. Check your email for complete access details.
                    </p>
                </div>
            @endif
        </div>

        <!-- Quick Actions -->
        <div class="bg-white rounded-lg shadow p-6 mb-8">
            <h3 class="text-lg font-bold mb-4">Quick Actions</h3>

            <div class="flex flex-wrap gap-3">
                @if ($deployment->isRunning())
                    <form method="POST" action="{{ route('customer.services.container.stop', $service) }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">
                            Stop Container
                        </button>
                    </form>

                    <form method="POST" action="{{ route('customer.services.container.restart', $service) }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            Restart Container
                        </button>
                    </form>
                @elseif ($deployment->status === 'stopped')
                    <form method="POST" action="{{ route('customer.services.container.start', $service) }}" style="display:inline;">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            Start Container
                        </button>
                    </form>
                @endif

                <button type="button" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700" onclick="toggleLogs()">
                    View Logs
                </button>
            </div>
        </div>

        <!-- Resource Usage Metrics -->
        <div class="bg-white rounded-lg shadow p-6 mb-8">
            <h3 class="text-lg font-bold mb-4">Resource Usage (Last 24 Hours)</h3>
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div>
                    <canvas id="cpuChart" height="80"></canvas>
                </div>
                <div>
                    <canvas id="memoryChart" height="80"></canvas>
                </div>
            </div>
        </div>

        <!-- Custom Domains -->
        <div class="bg-white rounded-lg shadow p-6 mb-8">
            <h3 class="text-lg font-bold mb-4">Custom Domains</h3>

            @if ($deployment->domains()->count() > 0)
                <div class="space-y-2 mb-4">
                    @foreach ($deployment->domains as $domain)
                        <div class="flex items-center justify-between bg-gray-50 p-3 rounded">
                            <div>
                                <p class="font-mono text-sm">{{ $domain->domain }}</p>
                                <div class="flex items-center gap-2 mt-1">
                                    @php
                                        $statusColor = match($domain->status) {
                                            'pending' => 'bg-blue-100 text-blue-800',
                                            'active' => 'bg-green-100 text-green-800',
                                            'failed' => 'bg-red-100 text-red-800',
                                            'removing' => 'bg-yellow-100 text-yellow-800',
                                            default => 'bg-gray-100 text-gray-800',
                                        };
                                    @endphp
                                    <span class="px-2 py-1 rounded text-xs font-semibold {{ $statusColor }}">
                                        {{ ucfirst($domain->status) }}
                                    </span>
                                    @if ($domain->ssl_enabled && $domain->status === 'active')
                                        <span class="px-2 py-1 rounded text-xs font-semibold bg-green-100 text-green-800">SSL ✓</span>
                                    @endif
                                </div>
                            </div>
                            <div class="flex gap-2">
                                @if ($domain->status === 'active' && !$domain->ssl_enabled)
                                    <form method="POST" action="{{ route('customer.services.container.domains.ssl', [$service, $domain]) }}" style="display:inline;">
                                        @csrf
                                        <button type="submit" class="px-3 py-1 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">
                                            Enable SSL
                                        </button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('customer.services.container.domains.unbind', [$service, $domain]) }}" style="display:inline;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1 bg-red-600 text-white text-sm rounded hover:bg-red-700" onclick="return confirm('Remove this domain?')">
                                        Remove
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                    <p class="text-sm text-blue-800"><strong>DNS Setup:</strong> Point your domain's A record to <code class="font-mono">{{ $deployment->node->ip_address }}</code></p>
                </div>
            @endif

            <form method="POST" action="{{ route('customer.services.container.domains.bind', $service) }}" class="flex gap-2">
                @csrf
                <input type="text" name="domain" placeholder="example.com" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-sm" required>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 text-sm">
                    Bind Domain
                </button>
            </form>
        </div>

        <!-- Logs Panel -->
        <div id="logs-container" class="bg-white rounded-lg shadow p-6 hidden mb-8">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold">Recent Logs</h3>
                <button type="button" class="text-gray-500 hover:text-gray-700" onclick="toggleLogs()">✕</button>
            </div>
            <div id="logs-content" class="bg-gray-900 text-gray-300 p-4 rounded font-mono text-sm overflow-x-auto max-h-96">
                <p class="text-gray-500">Loading logs...</p>
            </div>
        </div>

        <!-- Billing -->
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="text-lg font-bold mb-4">Billing</h3>
            <p class="text-gray-600">Base fee includes:</p>
            <ul class="list-disc list-inside text-gray-600 mt-2">
                <li>{{ $service->product->containerTemplate->required_cpu_cores }} CPU cores</li>
                <li>{{ $service->product->containerTemplate->required_ram_mb }}MB RAM</li>
                <li>{{ $service->product->containerTemplate->required_storage_gb }}GB Storage</li>
            </ul>
            <p class="text-sm text-gray-500 mt-4">
                Overage charges apply if resource usage exceeds allocated limits.
            </p>
        </div>
    @else
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
            <p class="text-yellow-800">Container deployment in progress...</p>
        </div>
    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    let cpuChart = null;
    let memoryChart = null;

    function initializeCharts() {
        fetch('{{ route("customer.services.container.metrics", $service) }}')
            .then(response => response.json())
            .then(data => {
                if (data.labels && data.labels.length > 0) {
                    renderCpuChart(data);
                    renderMemoryChart(data);
                }
            })
            .catch(error => console.error('Failed to load metrics:', error));
    }

    function renderCpuChart(data) {
        const ctx = document.getElementById('cpuChart').getContext('2d');
        if (cpuChart) cpuChart.destroy();
        cpuChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'CPU %',
                    data: data.cpu,
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    borderWidth: 1,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'top' } },
                scales: { y: { beginAtZero: true, max: 100 } }
            }
        });
    }

    function renderMemoryChart(data) {
        const ctx = document.getElementById('memoryChart').getContext('2d');
        if (memoryChart) memoryChart.destroy();
        memoryChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: data.labels,
                datasets: [{
                    label: 'Memory (MB)',
                    data: data.memory,
                    borderColor: 'rgb(34, 197, 94)',
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    borderWidth: 1,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'top' } },
                scales: { y: { beginAtZero: true } }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', initializeCharts);

    function toggleLogs() {
        const container = document.getElementById('logs-container');
        container.classList.toggle('hidden');

        if (!container.classList.contains('hidden')) {
            fetchLogs();
        }
    }

    function fetchLogs() {
        const logsContent = document.getElementById('logs-content');
        logsContent.innerHTML = '<p class="text-gray-500">Loading logs...</p>';

        fetch('{{ route("customer.services.container.logs", $service) }}')
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    logsContent.innerHTML = '<p class="text-red-400">Error: ' + data.error + '</p>';
                } else {
                    logsContent.textContent = data.logs || 'No logs available';
                }
            })
            .catch(error => {
                logsContent.innerHTML = '<p class="text-red-400">Failed to fetch logs</p>';
                console.error('Error:', error);
            });
    }
</script>
@endsection
