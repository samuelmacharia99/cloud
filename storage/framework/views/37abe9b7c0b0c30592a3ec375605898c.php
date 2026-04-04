<?php $__env->startSection('title', 'Edit Service'); ?>

<?php $__env->startSection('content'); ?>
<div class="max-w-2xl space-y-8">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Edit Service</h1>
        <p class="text-slate-600 mt-1">Update service details and status.</p>
    </div>

    <form action="<?php echo e(route('services.update', $service)); ?>" method="POST" class="bg-white rounded-2xl border border-slate-200 p-8 space-y-6">
        <?php echo csrf_field(); ?>
        <?php echo method_field('PUT'); ?>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Service Name</label>
            <input type="text" name="name" value="<?php echo e($service->name); ?>" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-red-600 text-sm mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Status</label>
            <select name="status" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="active" <?php echo e($service->status === 'active' ? 'selected' : ''); ?>>Active</option>
                <option value="suspended" <?php echo e($service->status === 'suspended' ? 'selected' : ''); ?>>Suspended</option>
                <option value="terminated" <?php echo e($service->status === 'terminated' ? 'selected' : ''); ?>>Terminated</option>
                <option value="cancelled" <?php echo e($service->status === 'cancelled' ? 'selected' : ''); ?>>Cancelled</option>
            </select>
            <?php $__errorArgs = ['status'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-red-600 text-sm mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Billing Cycle</label>
            <select name="billing_cycle" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="monthly" <?php echo e($service->billing_cycle === 'monthly' ? 'selected' : ''); ?>>Monthly</option>
                <option value="quarterly" <?php echo e($service->billing_cycle === 'quarterly' ? 'selected' : ''); ?>>Quarterly</option>
                <option value="semi-annual" <?php echo e($service->billing_cycle === 'semi-annual' ? 'selected' : ''); ?>>Semi-Annual</option>
                <option value="annual" <?php echo e($service->billing_cycle === 'annual' ? 'selected' : ''); ?>>Annual</option>
            </select>
            <?php $__errorArgs = ['billing_cycle'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-red-600 text-sm mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Next Due Date</label>
            <input type="date" name="next_due_date" value="<?php echo e($service->next_due_date->format('Y-m-d')); ?>" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            <?php $__errorArgs = ['next_due_date'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-red-600 text-sm mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 transition-colors">
                Update Service
            </button>
            <a href="<?php echo e(route('services.show', $service)); ?>" class="px-6 py-2.5 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/services/edit.blade.php ENDPATH**/ ?>