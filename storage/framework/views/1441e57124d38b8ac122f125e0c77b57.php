<?php $__env->startSection('title', 'Create Reseller Package'); ?>

<?php $__env->startSection('content'); ?>
<div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <a href="<?php echo e(route('admin.reseller-packages.index')); ?>" class="text-blue-600 dark:text-blue-400 hover:underline text-sm mb-3 inline-block">
            ← Back to Packages
        </a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Create Reseller Package</h1>
    </div>

    <!-- Form -->
    <form action="<?php echo e(route('admin.reseller-packages.store')); ?>" method="POST" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6">
        <?php echo csrf_field(); ?>

        <!-- Package Name -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Package Name</label>
            <input type="text" name="name" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="e.g., Starter, Professional, Enterprise" required value="<?php echo e(old('name')); ?>">
            <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="text-red-600 dark:text-red-400 text-xs mt-1"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <!-- Description -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Description</label>
            <textarea name="description" rows="3" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="Brief description of this package..."><?php echo e(old('description')); ?></textarea>
            <?php $__errorArgs = ['description'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="text-red-600 dark:text-red-400 text-xs mt-1"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <!-- Billing Cycle -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle</label>
            <div class="flex gap-4">
                <label class="flex items-center gap-2">
                    <input type="radio" name="billing_cycle" value="monthly" class="rounded border-slate-300" <?php echo e(old('billing_cycle') === 'monthly' ? 'checked' : ''); ?> required>
                    <span class="text-sm text-slate-700 dark:text-slate-300">Monthly</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="radio" name="billing_cycle" value="annually" class="rounded border-slate-300" <?php echo e(old('billing_cycle') === 'annually' ? 'checked' : ''); ?>>
                    <span class="text-sm text-slate-700 dark:text-slate-300">Annually</span>
                </label>
            </div>
            <?php $__errorArgs = ['billing_cycle'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="text-red-600 dark:text-red-400 text-xs mt-1"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <!-- Storage Space -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Storage Space (GB)</label>
            <input type="number" name="storage_space" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="e.g., 100" min="1" max="10000" required value="<?php echo e(old('storage_space')); ?>">
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Amount of cloud storage space in gigabytes</p>
            <?php $__errorArgs = ['storage_space'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="text-red-600 dark:text-red-400 text-xs mt-1"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <!-- Max Users -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Maximum Users</label>
            <input type="number" name="max_users" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="e.g., 5" min="1" max="1000" required value="<?php echo e(old('max_users')); ?>">
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Number of user accounts allowed for this package</p>
            <?php $__errorArgs = ['max_users'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="text-red-600 dark:text-red-400 text-xs mt-1"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <!-- Price -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Price (KES)</label>
            <input type="number" name="price" step="0.01" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="e.g., 5000.00" min="0" required value="<?php echo e(old('price')); ?>">
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Price per billing cycle</p>
            <?php $__errorArgs = ['price'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="text-red-600 dark:text-red-400 text-xs mt-1"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <!-- Active Toggle -->
        <div>
            <label class="flex items-center gap-3">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" class="rounded border-slate-300" <?php echo e(old('active') ? 'checked' : 'checked'); ?>>
                <span class="text-sm font-medium text-slate-900 dark:text-white">Active</span>
            </label>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Inactive packages won't be available for purchase</p>
        </div>

        <!-- Form Actions -->
        <div class="flex gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                Create Package
            </button>
            <a href="<?php echo e(route('admin.reseller-packages.index')); ?>" class="px-6 py-2 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg font-medium transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/reseller-packages/create.blade.php ENDPATH**/ ?>