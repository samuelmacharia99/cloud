<?php $__env->startSection('title', 'Service #' . $service->id); ?>

<?php $__env->startSection('breadcrumb'); ?>
<div class="flex items-center gap-2 text-sm">
    <a href="<?php echo e(route('admin.services.index')); ?>" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Services</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">#<?php echo e($service->id); ?></p>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Service #<?php echo e($service->id); ?></h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2"><?php echo e($service->product->name); ?> • <?php echo e(ucfirst(str_replace('_', ' ', $service->product->type))); ?></p>

                <!-- Status badge -->
                <div class="mt-4">
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
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-2 flex-wrap">
                <?php if($service->status === 'pending'): ?>
                    <form method="POST" action="<?php echo e(route('admin.services.provision', $service)); ?>" class="inline">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                            Provision
                        </button>
                    </form>
                <?php endif; ?>

                <?php if($service->status === 'active'): ?>
                    <form method="POST" action="<?php echo e(route('admin.services.suspend', $service)); ?>" class="inline">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-medium rounded-lg transition text-sm">
                            Suspend
                        </button>
                    </form>
                <?php endif; ?>

                <?php if($service->status === 'suspended'): ?>
                    <form method="POST" action="<?php echo e(route('admin.services.unsuspend', $service)); ?>" class="inline">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white font-medium rounded-lg transition text-sm">
                            Unsuspend
                        </button>
                    </form>
                <?php endif; ?>

                <?php if(in_array($service->status, ['active', 'suspended', 'pending'])): ?>
                    <form method="POST" action="<?php echo e(route('admin.services.terminate', $service)); ?>" class="inline" onsubmit="return confirm('Are you sure you want to terminate this service?');">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition text-sm">
                            Terminate
                        </button>
                    </form>
                <?php endif; ?>

                <form method="POST" action="<?php echo e(route('admin.services.refresh-status', $service)); ?>" class="inline">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="px-4 py-2 bg-slate-600 hover:bg-slate-700 text-white font-medium rounded-lg transition text-sm">
                        Refresh Status
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Service Details -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Service Details</h2>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Status</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e(ucfirst($service->status)); ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Billing Cycle</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e(ucfirst($service->billing_cycle)); ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Next Due Date</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e($service->next_due_date?->format('M d, Y') ?? '-'); ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e($service->created_at->format('M d, Y')); ?></p>
                    </div>
                    <?php if($service->suspend_date): ?>
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Suspended</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e($service->suspend_date->format('M d, Y')); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if($service->terminate_date): ?>
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Terminated</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1"><?php echo e($service->terminate_date->format('M d, Y')); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Configuration -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Configuration</h2>
                <div class="space-y-3">
                    <?php if($service->provisioning_driver_key): ?>
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Provisioning Driver</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1"><?php echo e($service->provisioning_driver_key); ?></p>
                        </div>
                    <?php endif; ?>
                    <?php if($service->external_reference): ?>
                        <div class="p-3 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">External Reference</p>
                            <p class="text-sm text-slate-900 dark:text-white font-mono mt-1"><?php echo e($service->external_reference); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Service Metadata -->
            <?php if($service->service_meta): ?>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Service Metadata</h2>
                    <pre class="bg-slate-50 dark:bg-slate-800 p-4 rounded-lg text-xs text-slate-900 dark:text-slate-100 overflow-x-auto"><?php echo e(json_encode($service->service_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold">
                            <?php echo e(strtoupper(substr($service->user->name, 0, 1))); ?>

                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white"><?php echo e($service->user->name); ?></p>
                            <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($service->user->email); ?></p>
                        </div>
                    </div>
                    <a href="<?php echo e(route('admin.customers.show', $service->user)); ?>" class="block mt-4 px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Customer
                    </a>
                </div>
            </div>

            <!-- Product Info -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Product</h3>
                <div class="space-y-3">
                    <div>
                        <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($service->product->name); ?></p>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-1"><?php echo e(ucfirst(str_replace('_', ' ', $service->product->type))); ?></p>
                    </div>
                    <a href="<?php echo e(route('admin.products.show', $service->product)); ?>" class="block px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Product
                    </a>
                </div>
            </div>

            <!-- Related Invoice -->
            <?php if($service->invoice): ?>
                <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Invoice</h3>
                    <div class="space-y-3">
                        <p class="text-sm text-slate-900 dark:text-white">Invoice #<?php echo e(str_pad($service->invoice->id, 5, '0', STR_PAD_LEFT)); ?></p>
                        <a href="<?php echo e(route('admin.invoices.show', $service->invoice)); ?>" class="block px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                            View Invoice
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-slate-900 dark:text-white"><?php echo e($service->created_at->format('M d, Y \a\t h:i A')); ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Last Updated</p>
                        <p class="text-slate-900 dark:text-white"><?php echo e($service->updated_at->format('M d, Y \a\t h:i A')); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/services/show.blade.php ENDPATH**/ ?>