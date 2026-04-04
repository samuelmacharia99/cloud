<?php $__env->startSection('title', 'Services'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Services</h1>
            <p class="text-slate-600 mt-1">Manage your active services and subscriptions.</p>
        </div>
        <?php if(auth()->guard()->check()): ?>
            <?php if(auth()->user()->is_admin): ?>
                <a href="<?php echo e(route('services.create')); ?>" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors">
                    + New Service
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Services Table -->
    <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Service</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Product</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold text-slate-900 uppercase">Next Due</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold text-slate-900 uppercase">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php $__empty_1 = true; $__currentLoopData = $services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-6 py-4">
                                <p class="font-semibold text-slate-900"><?php echo e($service->name); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600"><?php echo e($service->product->name); ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-xs font-medium <?php echo e($service->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-700'); ?>">
                                    <?php echo e(ucfirst($service->status)); ?>

                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <p class="text-sm text-slate-600"><?php echo e($service->next_due_date->format('M d, Y')); ?></p>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="<?php echo e(route('services.show', $service)); ?>" class="text-sm font-medium text-blue-600 hover:text-blue-700">View</a>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <p class="text-slate-500">No services found</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if($services->hasPages()): ?>
        <div class="flex items-center justify-center">
            <?php echo e($services->links()); ?>

        </div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/services/index.blade.php ENDPATH**/ ?>