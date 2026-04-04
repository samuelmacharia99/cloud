<?php $__env->startSection('title', 'Resellers'); ?>

<?php $__env->startSection('breadcrumb'); ?>
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Resellers</p>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Resellers</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage reseller accounts and commissions.</p>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Resellers</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($resellers->total()); ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Services Managed</p>
            <p class="text-3xl font-bold text-blue-600 dark:text-blue-400 mt-2"><?php echo e($totalServices); ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Unique Customers</p>
            <p class="text-3xl font-bold text-green-600 dark:text-green-400 mt-2"><?php echo e($totalCustomers); ?></p>
        </div>
    </div>

    <!-- Resellers Table -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Reseller</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Company</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Services</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Customers</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Revenue</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Created</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php $__empty_1 = true; $__currentLoopData = $resellers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $reseller): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <!-- Reseller Info -->
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white font-bold text-sm">
                                        <?php echo e(substr($reseller->name, 0, 1)); ?>

                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($reseller->name); ?></p>
                                        <p class="text-xs text-slate-500 dark:text-slate-400"><?php echo e($reseller->email); ?></p>
                                    </div>
                                </div>
                            </td>

                            <!-- Company Name -->
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                <?php echo e($reseller->company_name ?? '—'); ?>

                            </td>

                            <!-- Services Count -->
                            <td class="px-6 py-4 text-center">
                                <span class="inline-block px-3 py-1 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300 rounded-full text-sm font-medium">
                                    <?php echo e($reseller->managed_services_count ?? 0); ?>

                                </span>
                            </td>

                            <!-- Customers Count -->
                            <td class="px-6 py-4 text-center">
                                <span class="inline-block px-3 py-1 bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 rounded-full text-sm font-medium">
                                    <?php echo e($reseller->customers_count ?? 0); ?>

                                </span>
                            </td>

                            <!-- Revenue -->
                            <td class="px-6 py-4 text-right">
                                <span class="text-sm font-medium text-slate-900 dark:text-white">
                                    <?php if (isset($component)) { $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.currency-formatter','data' => ['amount' => $reseller->total_revenue ?? 0,'currency' => 'KES']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('currency-formatter'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['amount' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($reseller->total_revenue ?? 0),'currency' => 'KES']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf)): ?>
<?php $attributes = $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf; ?>
<?php unset($__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf)): ?>
<?php $component = $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf; ?>
<?php unset($__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf); ?>
<?php endif; ?>
                                </span>
                            </td>

                            <!-- Created Date -->
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                <?php echo e($reseller->created_at->format('M d, Y')); ?>

                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-4 text-center">
                                <div class="flex items-center justify-center gap-3">
                                    <a href="<?php echo e(route('admin.resellers.show', $reseller)); ?>" class="text-blue-600 dark:text-blue-400 hover:underline text-sm font-medium" title="View reseller">
                                        View
                                    </a>
                                    <form method="POST" action="<?php echo e(route('admin.resellers.impersonate', $reseller)); ?>" class="inline">
                                        <?php echo csrf_field(); ?>
                                        <button type="submit" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition" title="View as this reseller">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <div class="space-y-2">
                                    <svg class="mx-auto w-12 h-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 10H9"/>
                                    </svg>
                                    <p class="text-slate-600 dark:text-slate-400 font-medium">No resellers found</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if($resellers->hasPages()): ?>
            <div class="border-t border-slate-200 dark:border-slate-800 px-6 py-4">
                <?php echo e($resellers->links()); ?>

            </div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/resellers/index.blade.php ENDPATH**/ ?>