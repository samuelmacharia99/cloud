<?php $__env->startSection('title', 'Confirm Password'); ?>

<?php $__env->startSection('content'); ?>
<div x-data="{ showPassword: false }" class="space-y-7">
    <!-- Header with Icon -->
    <div class="text-center space-y-4">
        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-100 to-amber-200 dark:from-amber-900/40 dark:to-amber-800/40 border border-amber-200 dark:border-amber-700/50 flex items-center justify-center mx-auto shadow-lg">
            <svg class="w-8 h-8 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
            </svg>
        </div>
        <div>
            <h1 class="text-4xl font-bold tracking-tight mb-2">Confirm your password</h1>
            <p class="text-base text-slate-600 dark:text-slate-400 font-medium">This is a secure area of your account</p>
        </div>
    </div>

    <!-- Confirmation Form -->
    <form method="POST" action="<?php echo e(route('password.confirm')); ?>" class="space-y-5">
        <?php echo csrf_field(); ?>

        <!-- Password Input -->
        <div class="space-y-2.5">
            <label for="password" class="block text-sm font-semibold text-slate-900 dark:text-white">
                Password
            </label>
            <div class="relative" style="overflow: hidden; height: 2.75rem;">
                <input
                    :type="showPassword ? 'text' : 'password'"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="••••••••"
                    class="auth-input pr-11"
                    style="padding-right: 2.75rem !important;"
                />
                <button
                    type="button"
                    @click="showPassword = !showPassword"
                    class="absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300 transition-colors"
                    aria-label="Toggle password visibility"
                >
                    <svg v-if="!showPassword" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                    <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-4.803m5.596-3.856a3.375 3.375 0 11-4.753 4.753m5.596-3.856a3.375 3.375 0 01-4.753 4.753M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 9.172M15.828 15.828m-7.071-7.071L15.828 15.828M9.172 15.828L15.828 9.172"></path>
                    </svg>
                </button>
            </div>
            <?php $__errorArgs = ['password'];
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

        <!-- Confirm Button -->
        <button type="submit" class="auth-btn-primary mt-2">
            Confirm password
        </button>
    </form>

    <!-- Help Text -->
    <div class="text-center text-xs text-slate-600 dark:text-slate-400">
        <p>Forgot your password? <a href="<?php echo e(route('password.request')); ?>" class="text-purple-600 dark:text-purple-400 hover:underline transition font-semibold">Reset it here</a></p>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.auth-premium', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/auth/confirm-password-premium.blade.php ENDPATH**/ ?>