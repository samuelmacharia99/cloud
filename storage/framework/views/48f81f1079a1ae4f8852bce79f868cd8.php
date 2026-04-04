<?php $__env->startSection('title', $product->name); ?>

<?php $__env->startSection('breadcrumb'); ?>
<div class="flex items-center gap-2 text-sm">
    <a href="<?php echo e(route('admin.products.index')); ?>" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Products</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium"><?php echo e($product->name); ?></p>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6" x-data="{ tab: 'overview' }">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white"><?php echo e($product->name); ?></h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2"><?php echo e($product->description ?: 'No description provided'); ?></p>

                <!-- Status badges -->
                <div class="flex items-center gap-3 mt-4">
                    <!-- Product type -->
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300">
                        <?php echo e(ucfirst(str_replace('_', ' ', $product->type))); ?>

                    </span>

                    <!-- Status -->
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo e($product->is_active ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400'); ?>">
                        <?php echo e($product->is_active ? 'Active' : 'Inactive'); ?>

                    </span>

                    <?php if($product->featured): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300">
                            Featured
                        </span>
                    <?php endif; ?>

                    <?php if($product->visible_to_resellers): ?>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300">
                            Reseller Visible
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2">
                <a href="<?php echo e(route('admin.products.edit', $product)); ?>" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                    Edit Product
                </a>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <div class="border-b border-slate-200 dark:border-slate-800">
        <div class="flex gap-8 overflow-x-auto">
            <button @click="tab = 'overview'" :class="tab === 'overview' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Overview
            </button>
            <button @click="tab = 'services'" :class="tab === 'services' ? 'border-b-2 border-blue-600 text-slate-900 dark:text-white' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'" class="px-4 py-4 font-medium text-sm transition whitespace-nowrap">
                Services
            </button>
        </div>
    </div>

    <!-- Tab Content -->

    <!-- Overview Tab -->
    <div x-show="tab === 'overview'" class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Stats Cards -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Active Services</p>
                <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($product->services_count ?? 0); ?></p>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Monthly Revenue</p>
                <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">
                    <?php if($product->monthly_price): ?>
                        $<?php echo e(number_format($product->monthly_price, 2)); ?>

                    <?php else: ?>
                        -
                    <?php endif; ?>
                </p>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Yearly Revenue</p>
                <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">
                    <?php if($product->yearly_price): ?>
                        $<?php echo e(number_format($product->yearly_price, 2)); ?>

                    <?php else: ?>
                        -
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Product Information -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Product Information</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Product Slug</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e($product->slug); ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Product Type</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e(ucfirst(str_replace('_', ' ', $product->type))); ?></p>
                    </div>
                    <?php if($product->provisioning_driver_key): ?>
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Provisioning Driver</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1 font-mono"><?php echo e($product->provisioning_driver_key); ?></p>
                        </div>
                    <?php endif; ?>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e($product->created_at->format('M d, Y')); ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Last Updated</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e($product->updated_at->format('M d, Y')); ?></p>
                    </div>
                </div>
            </div>

            <!-- Pricing Information -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Pricing</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Monthly Price</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">
                            <?php if($product->monthly_price): ?>
                                $<?php echo e(number_format($product->monthly_price, 2)); ?> / month
                            <?php else: ?>
                                Not set
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Yearly Price</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">
                            <?php if($product->yearly_price): ?>
                                $<?php echo e(number_format($product->yearly_price, 2)); ?> / year
                            <?php else: ?>
                                Not set
                            <?php endif; ?>
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Setup Fee</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">
                            <?php if($product->setup_fee): ?>
                                $<?php echo e(number_format($product->setup_fee, 2)); ?>

                            <?php else: ?>
                                No setup fee
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <?php if($product->resource_limits): ?>
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Resource Limits</h3>
                <pre class="bg-slate-50 dark:bg-slate-800 p-4 rounded-lg text-xs text-slate-900 dark:text-slate-100 overflow-x-auto"><?php echo e(json_encode($product->resource_limits, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
            </div>
        <?php endif; ?>
    </div>

    <!-- Services Tab -->
    <div x-show="tab === 'services'" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <?php if($product->services->count() > 0): ?>
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Customer</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Service ID</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Created</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php $__currentLoopData = $product->services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4">
                                    <div>
                                        <p class="font-medium text-slate-900 dark:text-white"><?php echo e($service->user->name ?? 'Unknown'); ?></p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($service->user->email ?? ''); ?></p>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400"><?php echo e($service->id); ?></td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300">
                                        Active
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400"><?php echo e($service->created_at->format('M d, Y')); ?></td>
                                <td class="px-6 py-4 text-right">
                                    <a href="<?php echo e(route('admin.customers.show', $service->user)); ?>" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 text-sm font-medium">View Customer</a>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="px-6 py-12 text-center">
                    <p class="text-slate-600 dark:text-slate-400">No active services using this product.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/products/show.blade.php ENDPATH**/ ?>