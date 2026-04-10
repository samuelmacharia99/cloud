<?php if(auth()->user()->is_admin): ?>
    

    <?php $__env->startSection('title', 'Profile Settings'); ?>

    <?php $__env->startSection('breadcrumb'); ?>
    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Profile Settings</p>
    <?php $__env->stopSection(); ?>

    <?php $__env->startSection('content'); ?>
    <div class="space-y-6 max-w-4xl">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Profile Settings</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your account profile and security.</p>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <?php echo $__env->make('profile.partials.update-profile-information-form', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <?php echo $__env->make('profile.partials.update-password-form', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        </div>
    </div>
    <?php $__env->stopSection(); ?>
<?php else: ?>
    

    <?php $__env->startSection('title', 'Profile Settings'); ?>

    <?php $__env->startSection('content'); ?>
    <div class="space-y-6 max-w-4xl">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Profile Settings</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your account profile information and email address.</p>
        </div>

        <div class="space-y-6">
            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <?php echo $__env->make('profile.partials.update-profile-information-form', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>

            <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
                <?php echo $__env->make('profile.partials.delete-user-form', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
            </div>
        </div>
    </div>
    <?php $__env->stopSection(); ?>
<?php endif; ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?>
<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/profile/edit.blade.php ENDPATH**/ ?>