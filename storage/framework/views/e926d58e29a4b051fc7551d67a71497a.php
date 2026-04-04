<?php $__env->startSection('title', $service->name); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <div>
        <a href="<?php echo e(route('services.index')); ?>" class="text-sm font-medium text-blue-600 hover:text-blue-700">← Back to services</a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Service Details -->
        <div class="lg:col-span-2 bg-white rounded-2xl border border-slate-200 p-8 space-y-6">
            <div>
                <h1 class="text-3xl font-bold text-slate-900"><?php echo e($service->name); ?></h1>
                <p class="text-slate-600 mt-2"><?php echo e($service->product->name); ?></p>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm font-medium text-slate-600 uppercase">Status</p>
                    <p class="text-lg font-semibold text-slate-900 mt-1"><?php echo e(ucfirst($service->status)); ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 uppercase">Billing Cycle</p>
                    <p class="text-lg font-semibold text-slate-900 mt-1"><?php echo e(ucfirst($service->billing_cycle)); ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 uppercase">Next Due Date</p>
                    <p class="text-lg font-semibold text-slate-900 mt-1"><?php echo e($service->next_due_date->format('M d, Y')); ?></p>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-600 uppercase">Created</p>
                    <p class="text-lg font-semibold text-slate-900 mt-1"><?php echo e($service->created_at->format('M d, Y')); ?></p>
                </div>
            </div>

            <?php if($service->notes): ?>
                <div class="pt-4 border-t border-slate-200">
                    <p class="text-sm font-medium text-slate-600 uppercase mb-2">Notes</p>
                    <p class="text-slate-700"><?php echo e($service->notes); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Product Details -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-4">Product</h3>
                <div class="space-y-3">
                    <div>
                        <p class="text-xs text-slate-600 uppercase">Price</p>
                        <p class="text-xl font-bold text-slate-900">$<?php echo e(number_format($service->product->price, 2)); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-slate-600 uppercase">Billing</p>
                        <p class="text-slate-900"><?php echo e(ucfirst($service->product->billing_cycle)); ?></p>
                    </div>
                    <?php if($service->product->setup_fee > 0): ?>
                        <div>
                            <p class="text-xs text-slate-600 uppercase">Setup Fee</p>
                            <p class="text-slate-900">$<?php echo e(number_format($service->product->setup_fee, 2)); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Customer -->
            <div class="bg-white rounded-2xl border border-slate-200 p-6">
                <h3 class="font-semibold text-slate-900 mb-4">Customer</h3>
                <p class="font-medium text-slate-900"><?php echo e($service->user->name); ?></p>
                <p class="text-sm text-slate-600"><?php echo e($service->user->email); ?></p>
            </div>

            <!-- Actions -->
            <?php if(auth()->guard()->check()): ?>
                <?php if(auth()->user()->is_admin): ?>
                    <div class="flex gap-2">
                        <a href="<?php echo e(route('services.edit', $service)); ?>" class="flex-1 px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50 transition-colors">
                            Edit
                        </a>
                        <form action="<?php echo e(route('services.destroy', $service)); ?>" method="POST" onsubmit="return confirm('Are you sure?');" class="flex-1">
                            <?php echo csrf_field(); ?>
                            <?php echo method_field('DELETE'); ?>
                            <button type="submit" class="w-full px-4 py-2 rounded-lg bg-red-100 text-red-700 text-sm font-medium hover:bg-red-200 transition-colors">
                                Delete
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/services/show.blade.php ENDPATH**/ ?>