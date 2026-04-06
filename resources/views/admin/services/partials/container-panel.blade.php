@if ($service->product?->type === 'container_hosting' && $service->containerDeployment)
    @php
        $deployment = $service->containerDeployment;
        $template = $service->product->containerTemplate;
    @endphp

    <div class="bg-white rounded-lg shadow p-6 mt-6">
        <h3 class="text-xl font-bold mb-6">Container Deployment</h3>

        <!-- Status Card -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="border rounded-lg p-4">
                <p class="text-sm text-gray-500 mb-1">Status</p>
                <div class="flex items-center gap-2">
                    @php
                        $statusColor = match($deployment->status) {
                            'running' => 'bg-green-100 text-green-800',
                            'stopped' => 'bg-yellow-100 text-yellow-800',
                            'deploying' => 'bg-blue-100 text-blue-800',
                            'failed' => 'bg-red-100 text-red-800',
                            'terminated' => 'bg-gray-100 text-gray-800',
                            default => 'bg-gray-100 text-gray-800'
                        };
                    @endphp
                    <span class="px-3 py-1 rounded-full text-sm font-semibold {{ $statusColor }}">
                        {{ ucfirst($deployment->status) }}
                    </span>
                </div>
            </div>

            <div class="border rounded-lg p-4">
                <p class="text-sm text-gray-500 mb-1">Container Name</p>
                <p class="font-mono text-sm break-all">{{ $deployment->container_name }}</p>
            </div>

            <div class="border rounded-lg p-4">
                <p class="text-sm text-gray-500 mb-1">Node</p>
                <p class="font-semibold">
                    @if ($deployment->node)
                        <a href="{{ route('admin.nodes.show', $deployment->node) }}" class="text-blue-600 hover:text-blue-700">
                            {{ $deployment->node->hostname }}
                        </a>
                    @else
                        <span class="text-gray-400">Not assigned</span>
                    @endif
                </p>
            </div>

            <div class="border rounded-lg p-4">
                <p class="text-sm text-gray-500 mb-1">Port</p>
                <p class="font-semibold">{{ $deployment->assigned_port }}</p>
            </div>
        </div>

        <!-- Resource Allocation -->
        <div class="mb-6 border rounded-lg p-4">
            <h4 class="font-semibold mb-4">Resource Allocation</h4>
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <p class="text-sm text-gray-500 mb-1">CPU Cores</p>
                    <p class="font-semibold">{{ $template->required_cpu_cores }}</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 mb-1">RAM</p>
                    <p class="font-semibold">{{ $template->required_ram_mb }}MB</p>
                </div>
                <div>
                    <p class="text-sm text-gray-500 mb-1">Storage</p>
                    <p class="font-semibold">{{ $template->required_storage_gb }}GB</p>
                </div>
            </div>
        </div>

        <!-- Access URL -->
        @if ($deployment->getAccessUrl())
            <div class="mb-6 border rounded-lg p-4 bg-blue-50">
                <p class="text-sm text-gray-500 mb-2">Access URL</p>
                <p class="font-mono text-sm break-all">
                    <a href="{{ $deployment->getAccessUrl() }}" target="_blank" class="text-blue-600 hover:text-blue-700">
                        {{ $deployment->getAccessUrl() }}
                    </a>
                </p>
            </div>
        @endif

        <!-- Deployment Details -->
        <div class="grid grid-cols-2 gap-4 mb-6">
            @if ($deployment->deployed_at)
                <div class="border rounded-lg p-4">
                    <p class="text-sm text-gray-500 mb-1">Deployed</p>
                    <p class="font-semibold">{{ $deployment->deployed_at->diffForHumans() }}</p>
                </div>
            @endif

            @if ($deployment->terminated_at)
                <div class="border rounded-lg p-4">
                    <p class="text-sm text-gray-500 mb-1">Terminated</p>
                    <p class="font-semibold">{{ $deployment->terminated_at->diffForHumans() }}</p>
                </div>
            @endif

            @if ($deployment->last_status_check_at)
                <div class="border rounded-lg p-4">
                    <p class="text-sm text-gray-500 mb-1">Last Status Check</p>
                    <p class="font-semibold">{{ $deployment->last_status_check_at->diffForHumans() }}</p>
                </div>
            @endif
        </div>

        <!-- Action Buttons -->
        <div class="flex flex-wrap gap-3 mb-6">
            @if ($deployment->isRunning())
                <form method="POST" action="{{ route('admin.services.container.stop', $service) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">
                        Stop
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.services.container.restart', $service) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Restart
                    </button>
                </form>
            @elseif ($deployment->status === 'stopped')
                <form method="POST" action="{{ route('admin.services.container.start', $service) }}" style="display:inline;">
                    @csrf
                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                        Start
                    </button>
                </form>
            @endif

            <form method="POST" action="{{ route('admin.services.container.redeploy', $service) }}" style="display:inline;">
                @csrf
                <button type="submit" class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700" onclick="return confirm('This will terminate and redeploy the container. Continue?')">
                    Redeploy
                </button>
            </form>

            <button type="button" class="px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700" onclick="toggleLogs()">
                View Logs
            </button>
        </div>

        <!-- Docker Compose YAML -->
        <div class="mb-6 border rounded-lg p-4">
            <button type="button" class="flex items-center justify-between w-full font-semibold mb-2" onclick="toggleCompose()">
                <span>Docker Compose Configuration</span>
                <span id="compose-toggle" class="text-gray-500">▼</span>
            </button>
            <pre id="compose-content" class="bg-gray-900 text-green-400 p-4 rounded text-sm overflow-x-auto hidden max-h-96">{{ $deployment->docker_compose_content }}</pre>
        </div>

        <!-- Logs Panel -->
        <div id="logs-container" class="border rounded-lg p-4 hidden">
            <div class="flex justify-between items-center mb-2">
                <h4 class="font-semibold">Recent Logs</h4>
                <button type="button" class="text-sm text-gray-500 hover:text-gray-700" onclick="toggleLogs()">✕</button>
            </div>
            <div id="logs-content" class="bg-gray-900 text-gray-300 p-4 rounded text-sm overflow-x-auto max-h-96">
                <p class="text-gray-500">Loading logs...</p>
            </div>
        </div>
    </div>

    <script>
        function toggleCompose() {
            const content = document.getElementById('compose-content');
            const toggle = document.getElementById('compose-toggle');
            content.classList.toggle('hidden');
            toggle.textContent = content.classList.contains('hidden') ? '▼' : '▲';
        }

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

            fetch('{{ route("admin.services.container.logs", $service) }}')
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
@endif
