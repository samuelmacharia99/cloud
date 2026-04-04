@extends('layouts.admin')

@section('title', 'Cron Jobs')

@section('content')
<div class="flex-1 overflow-auto">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-8 py-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white">Cron Jobs</h1>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Automation & Task Scheduling</p>
            </div>
            <button onclick="runAllJobs()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-all">
                Run All Enabled Jobs
            </button>
        </div>
    </div>

    <div class="p-8 space-y-8">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-medium">Total Jobs</p>
                        <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $stats['total_jobs'] }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-medium">Enabled</p>
                        <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $stats['enabled_jobs'] }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-lg bg-green-100 dark:bg-green-900 flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-600 dark:text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-medium">24h Runs</p>
                        <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">{{ $stats['total_runs_24h'] }}</p>
                    </div>
                    <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-900 flex items-center justify-center">
                        <svg class="w-6 h-6 text-purple-600 dark:text-purple-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-medium">24h Failures</p>
                        <p class="text-3xl font-bold {{ $stats['failed_runs_24h'] > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white' }} mt-2">
                            {{ $stats['failed_runs_24h'] }}
                            @if ($stats['failed_runs_24h'] > 0)
                                <span class="inline-block ml-2 px-2 py-1 bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 text-xs rounded">Alert</span>
                            @endif
                        </p>
                    </div>
                    <div class="w-12 h-12 rounded-lg bg-red-100 dark:bg-red-900 flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-600 dark:text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 0v2m0-6v-2m0 0V7a2 2 0 012-2h6a2 2 0 012 2v10a2 2 0 01-2 2h-6a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- 24h Performance Chart -->
        <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">24-Hour Performance</h2>
                <span class="text-sm text-slate-600 dark:text-slate-400">Last 24 hours</span>
            </div>
            <canvas id="performanceChart" height="80"></canvas>
        </div>

        <!-- Jobs Table -->
        <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                        <tr>
                            <th class="px-6 py-3 text-left font-semibold text-slate-900 dark:text-white">Job Name</th>
                            <th class="px-6 py-3 text-left font-semibold text-slate-900 dark:text-white">Schedule</th>
                            <th class="px-6 py-3 text-center font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-3 text-left font-semibold text-slate-900 dark:text-white">Last Run</th>
                            <th class="px-6 py-3 text-left font-semibold text-slate-900 dark:text-white">Next Run</th>
                            <th class="px-6 py-3 text-center font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        @forelse ($jobs as $job)
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-all">
                                <td class="px-6 py-4">
                                    <a href="{{ route('admin.cron.show', $job) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                        {{ $job->name }}
                                    </a>
                                    @if ($job->description)
                                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">{{ $job->description }}</p>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <code class="bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-slate-100 px-2 py-1 rounded text-xs font-mono">{{ $job->schedule }}</code>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    @if ($job->enabled)
                                        <span class="inline-block px-3 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 rounded-full text-xs font-medium">Enabled</span>
                                    @else
                                        <span class="inline-block px-3 py-1 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-full text-xs font-medium">Disabled</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if ($job->latestLog)
                                        <div class="flex items-center gap-2">
                                            @if ($job->latestLog->status === 'success')
                                                <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                                <span class="text-slate-900 dark:text-slate-100">{{ $job->latestLog->started_at->diffForHumans() }}</span>
                                            @elseif ($job->latestLog->status === 'failed')
                                                <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                                                <span class="text-red-600 dark:text-red-400">{{ $job->latestLog->started_at->diffForHumans() }}</span>
                                            @else
                                                <span class="w-2 h-2 bg-yellow-500 rounded-full"></span>
                                                <span class="text-yellow-600 dark:text-yellow-400">Running</span>
                                            @endif
                                        </div>
                                    @else
                                        <span class="text-slate-600 dark:text-slate-400">Never</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if ($job->next_run_at)
                                        <span class="text-slate-900 dark:text-slate-100">{{ $job->next_run_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-slate-600 dark:text-slate-400">Not scheduled</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-center gap-2">
                                        <button onclick="runJob({{ $job->id }}, '{{ addslashes($job->name) }}')" class="px-3 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 hover:bg-blue-200 dark:hover:bg-blue-800 rounded text-xs font-medium transition-all" title="Run now">
                                            <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M8 5v14l11-7z" />
                                            </svg>
                                        </button>
                                        <form action="{{ route('admin.cron.toggle', $job) }}" method="POST" class="inline">
                                            @csrf
                                            <button type="submit" class="px-3 py-1 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded text-xs font-medium transition-all" title="{{ $job->enabled ? 'Disable' : 'Enable' }}">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                                </svg>
                                            </button>
                                        </form>
                                        <a href="{{ route('admin.cron.show', $job) }}" class="px-3 py-1 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded text-xs font-medium transition-all" title="View details">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                            </svg>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">
                                    <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <p class="font-medium">No cron jobs found</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="bg-slate-50 dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 px-6 py-4">
                {{ $jobs->links() }}
            </div>
        </div>
    </div>
</div>

<!-- Output Modal -->
<div id="outputModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-900 rounded-lg max-w-2xl w-full max-h-[80vh] overflow-y-auto">
        <div class="sticky top-0 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-6 py-4 flex items-center justify-between">
            <h3 class="text-lg font-bold text-slate-900 dark:text-white">Job Output</h3>
            <button onclick="closeOutputModal()" class="text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <div class="p-6">
            <pre id="outputContent" class="bg-slate-950 text-emerald-400 p-4 rounded overflow-x-auto text-sm font-mono"></pre>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.x"></script>
<script>
    // Initialize performance chart
    const chartData = @json($chartData);
    const ctx = document.getElementById('performanceChart').getContext('2d');
    const performanceChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    label: 'Success',
                    data: chartData.success,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true,
                },
                {
                    label: 'Failed',
                    data: chartData.failed,
                    borderColor: '#ef4444',
                    backgroundColor: 'rgba(239, 68, 68, 0.1)',
                    tension: 0.4,
                    fill: true,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        color: document.documentElement.classList.contains('dark') ? '#e2e8f0' : '#0f172a'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        color: document.documentElement.classList.contains('dark') ? 'rgba(148, 163, 184, 0.1)' : 'rgba(226, 232, 240, 0.5)'
                    },
                    ticks: {
                        color: document.documentElement.classList.contains('dark') ? '#94a3b8' : '#64748b'
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        color: document.documentElement.classList.contains('dark') ? '#94a3b8' : '#64748b'
                    }
                }
            }
        }
    });

    function runJob(jobId, jobName) {
        const form = new FormData();
        const url = `/admin/cron/${jobId}/run`;

        fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            }
        })
        .then(response => response.json())
        .then(data => {
            document.getElementById('outputContent').textContent = data.output;
            document.getElementById('outputModal').classList.remove('hidden');
        })
        .catch(error => {
            document.getElementById('outputContent').textContent = 'Error: ' + error.message;
            document.getElementById('outputModal').classList.remove('hidden');
        });
    }

    function runAllJobs() {
        const buttons = document.querySelectorAll('button[onclick*="runJob"]');
        let index = 0;

        function runNext() {
            if (index < buttons.length) {
                buttons[index].click();
                index++;
                setTimeout(runNext, 1000);
            }
        }

        runNext();
    }

    function closeOutputModal() {
        document.getElementById('outputModal').classList.add('hidden');
    }

    // Close modal on escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeOutputModal();
        }
    });

    // Auto-refresh chart every 15 seconds
    setInterval(function() {
        fetch('{{ route("admin.cron.logs", ["job" => "system"]) }}'.replace('system', 'system'))
            .catch(e => {});
    }, 15000);
</script>
@endpush
@endsection
