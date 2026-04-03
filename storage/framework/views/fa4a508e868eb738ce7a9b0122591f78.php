<?php $__env->startSection('title', 'Services'); ?>

<?php $__env->startSection('breadcrumb'); ?>
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Services</p>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Services</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Manage customer services and subscriptions.</p>
    </div>

    <!-- Filters -->
    <form method="GET" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Search</label>
                <input type="text" name="search" value="<?php echo e(request('search')); ?>" placeholder="Service #, customer..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Status</label>
                <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="all">All Status</option>
                    <option value="pending" <?php if(request('status') === 'pending'): echo 'selected'; endif; ?>>Pending</option>
                    <option value="provisioning" <?php if(request('status') === 'provisioning'): echo 'selected'; endif; ?>>Provisioning</option>
                    <option value="active" <?php if(request('status') === 'active'): echo 'selected'; endif; ?>>Active</option>
                    <option value="suspended" <?php if(request('status') === 'suspended'): echo 'selected'; endif; ?>>Suspended</option>
                    <option value="terminated" <?php if(request('status') === 'terminated'): echo 'selected'; endif; ?>>Terminated</option>
                    <option value="failed" <?php if(request('status') === 'failed'): echo 'selected'; endif; ?>>Failed</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Product Type</label>
                <select name="type" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm">
                    <option value="all">All Types</option>
                    <option value="shared_hosting" <?php if(request('type') === 'shared_hosting'): echo 'selected'; endif; ?>>Shared Hosting</option>
                    <option value="container_hosting" <?php if(request('type') === 'container_hosting'): echo 'selected'; endif; ?>>Container Hosting</option>
                    <option value="domain" <?php if(request('type') === 'domain'): echo 'selected'; endif; ?>>Domain</option>
                    <option value="ssl" <?php if(request('type') === 'ssl'): echo 'selected'; endif; ?>>SSL Certificate</option>
                    <option value="email_hosting" <?php if(request('type') === 'email_hosting'): echo 'selected'; endif; ?>>Email Hosting</option>
                    <option value="sms_bundle" <?php if(request('type') === 'sms_bundle'): echo 'selected'; endif; ?>>SMS Bundle</option>
                    <option value="hotspot_plan" <?php if(request('type') === 'hotspot_plan'): echo 'selected'; endif; ?>>Hotspot Plan</option>
                </select>
            </div>
            <div class="flex items-end">
                <button type="submit" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">Filter</button>
            </div>
        </div>
    </form>

    <!-- Services Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Service ID</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Customer</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Product</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Billing Cycle</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Next Due</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php $__empty_1 = true; $__currentLoopData = $services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">#<?php echo e($service->id); ?></td>
                            <td class="px-6 py-4">
                                <div>
                                    <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($service->user->name); ?></p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($service->user->email); ?></p>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div>
                                    <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($service->product->name); ?></p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e(ucfirst(str_replace('_', ' ', $service->product->type))); ?></p>
                                </div>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400"><?php echo e(ucfirst($service->billing_cycle)); ?></td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    <?php if($service->status === 'active'): ?>
                                        bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                    <?php elseif($service->status === 'pending'): ?>
                                        bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                                    <?php elseif($service->status === 'provisioning'): ?>
                                        bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                                    <?php elseif($service->status === 'suspended'): ?>
                                        bg-orange-100 dark:bg-orange-950 text-orange-700 dark:text-orange-300
                                    <?php elseif($service->status === 'terminated'): ?>
                                        bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                    <?php elseif($service->status === 'failed'): ?>
                                        bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                    <?php endif; ?>
                                ">
                                    <?php echo e(ucfirst($service->status)); ?>

                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                <?php echo e($service->next_due_date?->format('M d, Y') ?? '-'); ?>

                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="<?php echo e(route('admin.services.show', $service)); ?>" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                                        View
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <p class="text-slate-600 dark:text-slate-400">No services found.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        <?php echo e($services->links()); ?>

    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/services/index.blade.php ENDPATH**/ ?>