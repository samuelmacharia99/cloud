<?php $__env->startSection('title', 'Nodes'); ?>

<?php $__env->startSection('breadcrumb'); ?>
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Nodes</p>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Infrastructure Nodes</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your servers and container hosts.</p>
        </div>
        <a href="<?php echo e(route('admin.nodes.create')); ?>" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Add Node
        </a>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Total Nodes</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1"><?php echo e($stats['total']); ?></p>
                </div>
                <div class="p-3 bg-slate-100 dark:bg-slate-800 rounded-lg">
                    <svg class="w-6 h-6 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Online</p>
                    <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-1"><?php echo e($stats['online']); ?></p>
                </div>
                <div class="p-3 bg-emerald-100 dark:bg-emerald-950 rounded-lg">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Offline</p>
                    <p class="text-2xl font-bold text-red-600 dark:text-red-400 mt-1"><?php echo e($stats['offline']); ?></p>
                </div>
                <div class="p-3 bg-red-100 dark:bg-red-950 rounded-lg">
                    <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400">Container Hosts</p>
                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400 mt-1"><?php echo e($stats['container_hosts']); ?></p>
                </div>
                <div class="p-3 bg-blue-100 dark:bg-blue-950 rounded-lg">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8 4m-8-4v10l8 4m0-10l8 4m-8-4v10"/></svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
                <input type="text" name="search" value="<?php echo e(request('search')); ?>" placeholder="Name, hostname, IP..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Type</label>
                <select name="type" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="">All Types</option>
                    <?php $__currentLoopData = $types; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($type); ?>" <?php if(request('type') === $type): echo 'selected'; endif; ?>><?php echo e(ucfirst(str_replace('_', ' ', $type))); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="">All Status</option>
                    <?php $__currentLoopData = $statuses; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($status); ?>" <?php if(request('status') === $status): echo 'selected'; endif; ?>><?php echo e(ucfirst($status)); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Region</label>
                <select name="region" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="">All Regions</option>
                    <?php $__currentLoopData = $regions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $region): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <option value="<?php echo e($region); ?>" <?php if(request('region') === $region): echo 'selected'; endif; ?>><?php echo e($region); ?></option>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">Filter</button>
            </div>
        </div>
    </form>

    <!-- Nodes Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Node</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Type</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">CPU / RAM</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Region</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Services</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Last Seen</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php $__empty_1 = true; $__currentLoopData = $nodes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $node): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4">
                                <div>
                                    <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($node->name); ?></p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 font-mono"><?php echo e($node->hostname); ?></p>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    <?php if($node->type === 'dedicated_server'): ?>
                                        bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300
                                    <?php elseif($node->type === 'container_host'): ?>
                                        bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                                    <?php elseif($node->type === 'load_balancer'): ?>
                                        bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300
                                    <?php elseif($node->type === 'database_server'): ?>
                                        bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                                    <?php endif; ?>
                                ">
                                    <?php echo e(ucfirst(str_replace('_', ' ', $node->type))); ?>

                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    <?php if($node->status === 'online'): ?>
                                        bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                    <?php elseif($node->status === 'offline'): ?>
                                        bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                    <?php elseif($node->status === 'degraded'): ?>
                                        bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                                    <?php elseif($node->status === 'maintenance'): ?>
                                        bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                                    <?php endif; ?>
                                ">
                                    <?php echo e(ucfirst($node->status)); ?>

                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="space-y-1 text-xs">
                                    <div class="flex items-center gap-2">
                                        <span class="w-12 text-slate-600 dark:text-slate-400">CPU:</span>
                                        <div class="w-24 bg-slate-200 dark:bg-slate-700 rounded-full h-2">
                                            <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo e(min($node->getCpuUsagePercentage(), 100)); ?>%"></div>
                                        </div>
                                        <span class="text-slate-600 dark:text-slate-400"><?php echo e($node->getCpuUsagePercentage()); ?>%</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="w-12 text-slate-600 dark:text-slate-400">RAM:</span>
                                        <div class="w-24 bg-slate-200 dark:bg-slate-700 rounded-full h-2">
                                            <div class="bg-amber-500 h-2 rounded-full" style="width: <?php echo e(min($node->getRamUsagePercentage(), 100)); ?>%"></div>
                                        </div>
                                        <span class="text-slate-600 dark:text-slate-400"><?php echo e($node->getRamUsagePercentage()); ?>%</span>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400"><?php echo e($node->region ?? '-'); ?></td>
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white"><?php echo e($node->services_count ?? 0); ?></td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                <?php echo e($node->last_heartbeat_at?->diffForHumans() ?? 'Never'); ?>

                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="<?php echo e(route('admin.nodes.show', $node)); ?>" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                                        View
                                    </a>
                                    <a href="<?php echo e(route('admin.nodes.edit', $node)); ?>" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                                        Edit
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center">
                                <p class="text-slate-600 dark:text-slate-400">No nodes found. <a href="<?php echo e(route('admin.nodes.create')); ?>" class="text-blue-600 dark:text-blue-400 hover:underline">Create one</a></p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        <?php echo e($nodes->links()); ?>

    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/nodes/index.blade.php ENDPATH**/ ?>