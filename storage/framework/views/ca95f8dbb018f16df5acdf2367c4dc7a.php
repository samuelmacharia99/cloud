<?php $__env->startSection('title', 'Two-Factor Authentication'); ?>

<?php $__env->startSection('content'); ?>
<div class="min-h-screen bg-gradient-to-br from-slate-900 to-slate-800 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Card -->
        <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-2xl p-8">
            <!-- Header -->
            <div class="mb-8 text-center">
                <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                </div>
                <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">Two-Factor Authentication</h1>
                <p class="text-slate-600 dark:text-slate-400">Enter the 6-digit code sent to your phone</p>
            </div>

            <!-- Verification Code Form -->
            <form method="POST" action="<?php echo e(route('auth.two-factor.verify-code')); ?>" class="space-y-6">
                <?php echo csrf_field(); ?>

                <!-- Code Input -->
                <div>
                    <label for="code" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                        Verification Code
                    </label>
                    <input
                        type="text"
                        id="code"
                        name="code"
                        maxlength="6"
                        placeholder="000000"
                        autocomplete="off"
                        autofocus
                        class="w-full px-4 py-3 text-center text-2xl tracking-widest rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition <?php $__errorArgs = ['code'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 focus:ring-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                        inputmode="numeric"
                    >
                    <?php $__errorArgs = ['code'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition duration-200 ease-in-out transform hover:scale-105"
                >
                    Verify Code
                </button>
            </form>

            <!-- Divider -->
            <div class="my-6 flex items-center gap-4">
                <div class="flex-1 h-px bg-slate-200 dark:bg-slate-700"></div>
                <span class="text-sm text-slate-500 dark:text-slate-400">or</span>
                <div class="flex-1 h-px bg-slate-200 dark:bg-slate-700"></div>
            </div>

            <!-- Recovery Code Form -->
            <form method="POST" action="<?php echo e(route('auth.two-factor.use-recovery-code')); ?>" class="space-y-4">
                <?php echo csrf_field(); ?>

                <!-- Recovery Code Input -->
                <div>
                    <label for="recovery_code" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">
                        Recovery Code
                    </label>
                    <input
                        type="text"
                        id="recovery_code"
                        name="recovery_code"
                        placeholder="XXXXXXXX"
                        autocomplete="off"
                        class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-900 dark:text-white placeholder-slate-400 dark:placeholder-slate-500 focus:ring-2 focus:ring-blue-500 focus:border-transparent transition <?php $__errorArgs = ['recovery_code'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 focus:ring-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                    >
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">If you don't have access to your phone</p>
                    <?php $__errorArgs = ['recovery_code'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                        <p class="mt-2 text-sm text-red-600 dark:text-red-400"><?php echo e($message); ?></p>
                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                </div>

                <!-- Submit Button -->
                <button
                    type="submit"
                    class="w-full px-4 py-3 bg-slate-200 dark:bg-slate-700 hover:bg-slate-300 dark:hover:bg-slate-600 text-slate-900 dark:text-white font-medium rounded-lg transition"
                >
                    Use Recovery Code
                </button>
            </form>

            <!-- Help Text -->
            <div class="mt-6 pt-6 border-t border-slate-200 dark:border-slate-700">
                <p class="text-xs text-slate-600 dark:text-slate-400 text-center">
                    Code expires in <strong>5 minutes</strong>. Didn't receive the code?
                    <a href="<?php echo e(route('login')); ?>" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                        Try again
                    </a>
                </p>
            </div>
        </div>

        <!-- Footer -->
        <p class="text-center text-sm text-slate-400 mt-6">
            Talksasa Cloud &copy; 2026
        </p>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/auth/two-factor-verify.blade.php ENDPATH**/ ?>