<?php $__env->startSection('title', $job->name); ?>

<?php $__env->startSection('content'); ?>
<div class="flex-1 overflow-auto">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 px-8 py-6">
        <div class="flex items-center justify-between mb-4">
            <div>
                <a href="<?php echo e(route('admin.cron.index')); ?>" class="text-sm text-blue-600 dark:text-blue-400 hover:underline mb-2 inline-block">← Back to Cron Jobs</a>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo e($job->name); ?></h1>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">
                    <code class="bg-slate-100 dark:bg-slate-800 px-2 py-1 rounded text-xs font-mono"><?php echo e($job->command); ?></code>
                </p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="runNow()" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-all">
                    Run Now
                </button>
                <form action="<?php echo e(route('admin.cron.toggle', $job)); ?>" method="POST" class="inline">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="px-4 py-2 <?php echo e($job->enabled ? 'bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 hover:bg-red-200 dark:hover:bg-red-800' : 'bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 hover:bg-green-200 dark:hover:bg-green-800'); ?> rounded-lg font-medium transition-all">
                        <?php echo e($job->enabled ? 'Disable Job' : 'Enable Job'); ?>

                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="p-8 space-y-8">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-medium">Total Runs</p>
                        <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($stats['total_runs']); ?></p>
                    </div>
                    <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                        <svg class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-medium">Success Rate</p>
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2"><?php echo e($stats['success_rate']); ?>%</p>
                    </div>
                    <div class="w-12 h-12 rounded-lg bg-green-100 dark:bg-green-900 flex items-center justify-center">
                        <svg class="w-6 h-6 text-green-600 dark:text-green-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m7 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-medium">Avg Duration</p>
                        <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">
                            <?php if($stats['avg_duration'] < 1000): ?>
                                <?php echo e($stats['avg_duration']); ?>ms
                            <?php else: ?>
                                <?php echo e(number_format($stats['avg_duration'] / 1000, 2)); ?>s
                            <?php endif; ?>
                        </p>
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
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-medium">Last Status</p>
                        <p class="text-sm font-bold mt-2">
                            <?php if($stats['last_status'] === 'success'): ?>
                                <span class="inline-flex items-center gap-2 text-green-700 dark:text-green-400">
                                    <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                    Success
                                </span>
                            <?php elseif($stats['last_status'] === 'failed'): ?>
                                <span class="inline-flex items-center gap-2 text-red-700 dark:text-red-400">
                                    <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                                    Failed
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-2 text-yellow-700 dark:text-yellow-400">
                                    <span class="w-2 h-2 bg-yellow-500 rounded-full"></span>
                                    Running
                                </span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="w-12 h-12 rounded-lg bg-slate-100 dark:bg-slate-800 flex items-center justify-center">
                        <svg class="w-6 h-6 text-slate-600 dark:text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- Duration Trend Chart -->
        <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Duration Trend (24h)</h2>
                <span class="text-sm text-slate-600 dark:text-slate-400">Average execution time per hour</span>
            </div>
            <canvas id="durationChart" height="80"></canvas>
        </div>

        <!-- Logs Table -->
        <div class="bg-white dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-200 dark:border-slate-700">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white">Execution History</h2>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                        <tr>
                            <th class="px-6 py-3 text-left font-semibold text-slate-900 dark:text-white">Timestamp</th>
                            <th class="px-6 py-3 text-left font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-3 text-left font-semibold text-slate-900 dark:text-white">Duration</th>
                            <th class="px-6 py-3 text-left font-semibold text-slate-900 dark:text-white">Output</th>
                            <th class="px-6 py-3 text-center font-semibold text-slate-900 dark:text-white">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <?php $__empty_1 = true; $__currentLoopData = $logs; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-all">
                                <td class="px-6 py-4">
                                    <span class="text-slate-900 dark:text-slate-100"><?php echo e($log->started_at->format('M d, Y H:i:s')); ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if($log->status === 'success'): ?>
                                        <span class="inline-flex items-center gap-2 px-3 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 rounded-full text-xs font-medium">
                                            <span class="w-2 h-2 bg-green-500 rounded-full"></span>
                                            Success
                                        </span>
                                    <?php elseif($log->status === 'failed'): ?>
                                        <span class="inline-flex items-center gap-2 px-3 py-1 bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 rounded-full text-xs font-medium">
                                            <span class="w-2 h-2 bg-red-500 rounded-full"></span>
                                            Failed
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-2 px-3 py-1 bg-yellow-100 dark:bg-yellow-900 text-yellow-700 dark:text-yellow-300 rounded-full text-xs font-medium">
                                            <span class="w-2 h-2 bg-yellow-500 rounded-full"></span>
                                            Running
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if($log->duration_ms): ?>
                                        <span class="text-slate-900 dark:text-slate-100 font-mono">
                                            <?php if($log->duration_ms < 1000): ?>
                                                <?php echo e($log->duration_ms); ?>ms
                                            <?php else: ?>
                                                <?php echo e(number_format($log->duration_ms / 1000, 2)); ?>s
                                            <?php endif; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-600 dark:text-slate-400">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if($log->output): ?>
                                        <p class="text-slate-900 dark:text-slate-100 truncate max-w-xs"><?php echo e(Str::limit($log->output, 50)); ?></p>
                                    <?php else: ?>
                                        <span class="text-slate-600 dark:text-slate-400">No output</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <button onclick="expandRow(<?php echo e($loop->index); ?>)" class="text-blue-600 dark:text-blue-400 hover:underline text-xs font-medium">
                                        View
                                    </button>
                                </td>
                            </tr>
                            <!-- Expandable Row -->
                            <tr id="expand-<?php echo e($loop->index); ?>" class="bg-slate-50 dark:bg-slate-800 hidden">
                                <td colspan="5" class="px-6 py-6">
                                    <?php if($log->output): ?>
                                        <div class="mb-6">
                                            <h4 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Output</h4>
                                            <pre class="bg-slate-950 text-emerald-400 p-4 rounded overflow-x-auto text-xs font-mono"><?php echo e($log->output); ?></pre>
                                        </div>
                                    <?php endif; ?>

                                    <?php if($log->exception): ?>
                                        <div>
                                            <h4 class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Exception</h4>
                                            <pre class="bg-red-950 text-red-300 p-4 rounded overflow-x-auto text-xs font-mono"><?php echo e($log->exception); ?></pre>
                                        </div>
                                    <?php endif; ?>

                                    <?php if(!$log->output && !$log->exception): ?>
                                        <p class="text-slate-600 dark:text-slate-400">No output or exception recorded.</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-slate-600 dark:text-slate-400">
                                    <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <p class="font-medium">No execution history</p>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div class="bg-slate-50 dark:bg-slate-800 border-t border-slate-200 dark:border-slate-700 px-6 py-4">
                <?php echo e($logs->links()); ?>

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

<?php $__env->startPush('scripts'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.x"></script>
<script>
    // Initialize duration chart
    const logsData = <?php echo json_encode($logs->map(fn($log) => [
        'hour' => $log->started_at->format('H:00'), 'duration' => $log->duration_ms ?? 0
    ]), 512) ?>;

    // Group by hour and calculate average
    const hourlyDurations = {};
    logsData.forEach(item => {
        if (!hourlyDurations[item.hour]) {
            hourlyDurations[item.hour] = { total: 0, count: 0 };
        }
        hourlyDurations[item.hour].total += item.duration;
        hourlyDurations[item.hour].count++;
    });

    const labels = Object.keys(hourlyDurations);
    const durations = labels.map(hour => {
        const avg = hourlyDurations[hour].total / hourlyDurations[hour].count;
        return avg < 1000 ? avg.toFixed(0) : (avg / 1000).toFixed(2);
    });

    const ctx = document.getElementById('durationChart').getContext('2d');
    const durationChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Avg Duration',
                    data: durations,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
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

    function runNow() {
        const jobId = <?php echo e($job->id); ?>;
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
            // Reload page after a short delay to show updated logs
            setTimeout(() => {
                location.reload();
            }, 2000);
        })
        .catch(error => {
            document.getElementById('outputContent').textContent = 'Error: ' + error.message;
            document.getElementById('outputModal').classList.remove('hidden');
        });
    }

    function expandRow(index) {
        const row = document.getElementById(`expand-${index}`);
        row.classList.toggle('hidden');
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

    // Auto-refresh duration chart every 15 seconds
    setInterval(function() {
        fetch('<?php echo e(route("admin.cron.logs", $job)); ?>')
            .then(response => response.json())
            .catch(e => {});
    }, 15000);
</script>
<?php $__env->stopPush(); ?>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/cron/show.blade.php ENDPATH**/ ?>