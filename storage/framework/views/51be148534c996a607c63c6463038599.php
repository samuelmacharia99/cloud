<?php $__env->startSection('title', 'Reset Password'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-7">
    <!-- Header -->
    <div class="space-y-2">
        <h1 class="text-4xl font-bold tracking-tight">Forgot your password?</h1>
        <p class="text-base text-slate-600 dark:text-slate-400 font-medium">Enter your email and we'll send you a reset link</p>
    </div>

    <!-- Session Status -->
    <?php if(session('status')): ?>
        <div class="p-4 rounded-md bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/40 text-xs text-emerald-700 dark:text-emerald-300 font-medium">
            <?php echo e(session('status')); ?>

        </div>
    <?php endif; ?>

    <!-- Reset Link Form -->
    <form method="POST" action="<?php echo e(route('password.email')); ?>" class="space-y-5">
        <?php echo csrf_field(); ?>

        <!-- Email Input -->
        <div class="space-y-2.5">
            <label for="email" class="block text-sm font-semibold text-slate-900 dark:text-white">
                Email address
            </label>
            <input
                type="email"
                id="email"
                name="email"
                value="<?php echo e(old('email')); ?>"
                required
                autofocus
                autocomplete="email"
                placeholder="me@company.com"
                class="auth-input"
            />
            <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                <p class="mt-1.5 text-xs font-medium text-red-600 dark:text-red-400"><?php echo e($message); ?></p>
            <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <!-- Send Button -->
        <button type="submit" class="auth-btn-primary mt-2">
            Send reset link
        </button>
    </form>

    <!-- Back to Login -->
    <div class="text-center text-sm">
        <a href="<?php echo e(route('login')); ?>" class="font-semibold text-purple-600 dark:text-purple-400 hover:text-purple-700 dark:hover:text-purple-300 transition">
            Back to sign in
        </a>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.auth-premium', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/auth/forgot-password-premium.blade.php ENDPATH**/ ?>