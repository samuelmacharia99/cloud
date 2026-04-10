<?php $__env->startSection('title', 'SMS Notifications'); ?>

<?php $__env->startSection('breadcrumb'); ?>
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">SMS Notifications</p>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">SMS Notifications</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Send SMS messages to customers and broadcast announcements.</p>
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Compose Section -->
        <div class="lg:col-span-2">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 space-y-6">
                <h2 class="text-xl font-bold text-slate-900 dark:text-white">Compose Message</h2>

                <form action="<?php echo e(route('admin.sms.send')); ?>" method="POST" x-data="{ recipientType: 'all', message: '', charCount: 0 }">
                    <?php echo csrf_field(); ?>

                    <!-- Recipient Type -->
                    <div class="space-y-3 mb-6">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" name="recipient_type" value="all" x-model="recipientType" class="rounded-full border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">All Active Customers</span>
                        </label>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" name="recipient_type" value="custom" x-model="recipientType" class="rounded-full border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Select Specific Customers</span>
                        </label>
                    </div>

                    <!-- Customer Multi-Select (shown when custom selected) -->
                    <div x-show="recipientType === 'custom'" class="mb-6">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Select Customers</label>
                        <select name="recipients[]" multiple class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                            <?php $__currentLoopData = $customers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $customer): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <option value="<?php echo e($customer->id); ?>"><?php echo e($customer->name); ?> (<?php echo e($customer->email); ?>)</option>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </select>
                        <p class="text-xs text-slate-600 dark:text-slate-400 mt-2">Hold Ctrl/Cmd to select multiple customers</p>
                    </div>

                    <!-- Message -->
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Message</label>
                        <textarea
                            name="message"
                            x-model="message"
                            @input="charCount = message.length"
                            maxlength="160"
                            required
                            class="w-full px-4 py-3 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500 resize-none h-24"
                            placeholder="Type your SMS message..."
                        ></textarea>
                        <div class="flex justify-between items-center mt-2">
                            <p class="text-xs text-slate-600 dark:text-slate-400">Max 160 characters</p>
                            <span class="text-xs font-medium" :class="charCount > 150 ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-400'">
                                <span x-text="charCount"></span>/160
                            </span>
                        </div>
                    </div>

                    <!-- Submit -->
                    <button
                        type="submit"
                        @click="if (!confirm('Send SMS to <?php echo e($customers->count()); ?> customer(s)?')) event.preventDefault();"
                        class="w-full px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors"
                    >
                        <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8m0 8l-9-2m9 2l9-2m-9-8l9 2m-9-2l-9 2"/>
                        </svg>
                        Send SMS
                    </button>
                </form>
            </div>
        </div>

        <!-- Recent Logs -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Recent Logs</h2>

                <div class="space-y-3 max-h-96 overflow-y-auto">
                    <?php $__empty_1 = true; $__currentLoopData = $logs->items(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $log): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <div class="pb-3 border-b border-slate-200 dark:border-slate-700 last:border-0 last:pb-0">
                            <div class="flex items-start justify-between gap-2">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-medium text-slate-600 dark:text-slate-400">
                                        <?php echo e($log->created_at->format('H:i')); ?>

                                    </p>
                                    <p class="text-sm text-slate-900 dark:text-white truncate">
                                        <?php echo e($log->recipient); ?>

                                    </p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 line-clamp-2">
                                        <?php echo e(substr($log->message, 0, 50)); ?><?php echo e(strlen($log->message) > 50 ? '...' : ''); ?>

                                    </p>
                                </div>
                                <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium flex-shrink-0 <?php echo e($log->status === 'sent' ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300'); ?>">
                                    <?php echo e(ucfirst($log->status)); ?>

                                </span>
                            </div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <p class="text-sm text-slate-500 dark:text-slate-400 text-center py-4">No logs yet</p>
                    <?php endif; ?>
                </div>

                <!-- Pagination Links -->
                <?php if($logs->hasPages()): ?>
                    <div class="mt-4 pt-4 border-t border-slate-200 dark:border-slate-700">
                        <div class="flex justify-between items-center text-xs text-slate-600 dark:text-slate-400">
                            <span>Page <?php echo e($logs->currentPage()); ?> of <?php echo e($logs->lastPage()); ?></span>
                            <div class="flex gap-1">
                                <?php if($logs->onFirstPage()): ?>
                                    <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-slate-400 rounded">← Prev</span>
                                <?php else: ?>
                                    <a href="<?php echo e($logs->previousPageUrl()); ?>" class="px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 dark:hover:bg-blue-800">← Prev</a>
                                <?php endif; ?>

                                <?php if($logs->hasMorePages()): ?>
                                    <a href="<?php echo e($logs->nextPageUrl()); ?>" class="px-2 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded hover:bg-blue-200 dark:hover:bg-blue-800">Next →</a>
                                <?php else: ?>
                                    <span class="px-2 py-1 bg-slate-100 dark:bg-slate-800 text-slate-400 rounded">Next →</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/sms/index.blade.php ENDPATH**/ ?>