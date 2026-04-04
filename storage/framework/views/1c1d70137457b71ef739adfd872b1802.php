<?php $__env->startSection('title', 'Emails'); ?>

<?php $__env->startSection('breadcrumb'); ?>
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Emails</p>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Email Log</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">View all emails sent from the system.</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Sent Today</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($totalSentToday); ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Failed Today</p>
            <p class="text-3xl font-bold text-red-600 dark:text-red-400 mt-2"><?php echo e($totalFailedToday); ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Sent</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($totalAllTime); ?></p>
        </div>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="flex gap-2 flex-wrap">
            <a href="<?php echo e(route('admin.emails.index')); ?>" class="px-4 py-2 rounded-lg font-medium text-sm transition-all <?php echo e($status === 'all' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'); ?>">
                All Emails
            </a>
            <a href="<?php echo e(route('admin.emails.index', ['status' => 'sent'])); ?>" class="px-4 py-2 rounded-lg font-medium text-sm transition-all <?php echo e($status === 'sent' ? 'bg-green-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'); ?>">
                Sent
            </a>
            <a href="<?php echo e(route('admin.emails.index', ['status' => 'failed'])); ?>" class="px-4 py-2 rounded-lg font-medium text-sm transition-all <?php echo e($status === 'failed' ? 'bg-red-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'); ?>">
                Failed
            </a>
            <a href="<?php echo e(route('admin.emails.index', ['status' => 'bounced'])); ?>" class="px-4 py-2 rounded-lg font-medium text-sm transition-all <?php echo e($status === 'bounced' ? 'bg-amber-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'); ?>">
                Bounced
            </a>
        </div>
    </div>

    <!-- Email Table -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Recipient</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Subject</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Sent By</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase tracking-wider">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php $__empty_1 = true; $__currentLoopData = $emails; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $email): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white"><?php echo e($email->recipient); ?></td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300 truncate max-w-xs"><?php echo e($email->subject); ?></td>
                            <td class="px-6 py-4 text-sm">
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium <?php echo e($email->status === 'sent' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : ($email->status === 'failed' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300')); ?>">
                                    <?php echo e(ucfirst($email->status)); ?>

                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                <?php echo e($email->sentBy?->name ?? 'System'); ?>

                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-300">
                                <?php echo e($email->created_at->format('M d, Y H:i')); ?>

                            </td>
                            <td class="px-6 py-4 text-sm">
                                <a href="<?php echo e(route('admin.emails.show', $email)); ?>" class="text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 font-medium">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-slate-500 dark:text-slate-400">
                                <svg class="w-12 h-12 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                <p class="text-sm font-medium">No emails found</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if($emails->hasPages()): ?>
            <div class="px-6 py-4 border-t border-slate-200 dark:border-slate-700">
                <div class="flex items-center justify-between">
                    <div class="text-sm text-slate-600 dark:text-slate-400">
                        Showing <span class="font-medium"><?php echo e($emails->firstItem()); ?></span> to <span class="font-medium"><?php echo e($emails->lastItem()); ?></span> of <span class="font-medium"><?php echo e($emails->total()); ?></span> emails
                    </div>
                    <div class="flex gap-2">
                        <?php if($emails->onFirstPage()): ?>
                            <span class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Previous</span>
                        <?php else: ?>
                            <a href="<?php echo e($emails->previousPageUrl()); ?>" class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-700">← Previous</a>
                        <?php endif; ?>

                        <?php if($emails->hasMorePages()): ?>
                            <a href="<?php echo e($emails->nextPageUrl()); ?>" class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 rounded-lg text-sm font-medium hover:bg-slate-200 dark:hover:bg-slate-700">Next →</a>
                        <?php else: ?>
                            <span class="px-3 py-2 bg-slate-100 dark:bg-slate-800 text-slate-400 dark:text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/emails/index.blade.php ENDPATH**/ ?>