<?php $__env->startSection('title', 'Verify Email'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-7">
    <!-- Header with Icon -->
    <div class="text-center space-y-4">
        <div class="w-16 h-16 rounded-2xl bg-gradient-to-br from-purple-100 to-purple-200 dark:from-purple-900/40 dark:to-purple-800/40 border border-purple-200 dark:border-purple-700/50 flex items-center justify-center mx-auto shadow-lg">
            <svg class="w-8 h-8 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
            </svg>
        </div>
        <div>
            <h1 class="text-4xl font-bold tracking-tight mb-2">Verify your email</h1>
            <p class="text-base text-slate-600 dark:text-slate-400 font-medium">We've sent a confirmation link to your address</p>
        </div>
    </div>

    <!-- Success Message -->
    <?php if(session('status') == 'verification-link-sent'): ?>
        <div class="p-4 rounded-md bg-emerald-50 dark:bg-emerald-950/30 border border-emerald-200 dark:border-emerald-800/40 text-sm text-emerald-700 dark:text-emerald-300">
            <div class="flex gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <p class="font-semibold text-xs">Verification link sent</p>
                    <p class="text-xs mt-0.5 text-emerald-600 dark:text-emerald-400">Check your email for the confirmation link. It may take a few moments to arrive.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Info Box -->
    <div class="p-4 rounded-md bg-slate-50 dark:bg-slate-900/30 border border-slate-200 dark:border-slate-700/50 text-sm">
        <div class="flex gap-3">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <div>
                <p class="font-semibold text-xs text-slate-900 dark:text-white">Didn't receive the email?</p>
                <p class="text-xs mt-0.5 text-slate-600 dark:text-slate-400">Check your spam folder, or request a new verification link below.</p>
            </div>
        </div>
    </div>

    <!-- Actions -->
    <div class="space-y-3">
        <!-- Resend Button -->
        <form method="POST" action="<?php echo e(route('verification.send')); ?>">
            <?php echo csrf_field(); ?>
            <button type="submit" class="auth-btn-primary">
                Resend verification email
            </button>
        </form>

        <!-- Sign Out Button -->
        <form method="POST" action="<?php echo e(route('logout')); ?>">
            <?php echo csrf_field(); ?>
            <button type="submit" class="auth-btn-secondary">
                Sign out
            </button>
        </form>
    </div>

    <!-- Help Text -->
    <div class="text-center text-xs text-slate-600 dark:text-slate-400">
        <p>Still having issues? <a href="#" class="text-purple-600 dark:text-purple-400 hover:underline transition font-semibold">Contact our support</a></p>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.auth-premium', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/auth/verify-email-premium.blade.php ENDPATH**/ ?>