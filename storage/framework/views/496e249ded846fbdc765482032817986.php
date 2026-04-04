<?php $__env->startSection('title', $order->order_number); ?>

<?php $__env->startSection('breadcrumb'); ?>
<div class="flex items-center gap-2 text-sm">
    <a href="<?php echo e(route('admin.orders.index')); ?>" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white transition">Orders</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium"><?php echo e($order->order_number); ?></p>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header Section -->
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white"><?php echo e($order->order_number); ?></h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage and track this customer order</p>
        </div>
        <div class="flex items-center gap-2">
            <button class="px-4 py-2.5 bg-white dark:bg-slate-900 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 font-medium rounded-lg transition text-sm flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8m0 8l-6-2m6 2l6-2"/>
                </svg>
                Actions
            </button>
        </div>
    </div>

    <!-- Status Cards Row -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Order Status</p>
                    <div class="mt-2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold
                            <?php if($order->status === 'pending'): ?>
                                bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                            <?php elseif($order->status === 'paid'): ?>
                                bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                            <?php elseif($order->status === 'cancelled'): ?>
                                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                            <?php elseif($order->status === 'failed'): ?>
                                bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                            <?php else: ?>
                                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                            <?php endif; ?>
                        ">
                            <?php echo e(ucfirst($order->status)); ?>

                        </span>
                    </div>
                </div>
                <div class="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Payment Status</p>
                    <div class="mt-2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold
                            <?php if($order->payment_status === 'paid'): ?>
                                bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                            <?php elseif($order->payment_status === 'unpaid'): ?>
                                bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
                            <?php elseif($order->payment_status === 'refunded'): ?>
                                bg-purple-100 dark:bg-purple-950 text-purple-700 dark:text-purple-300
                            <?php else: ?>
                                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                            <?php endif; ?>
                        ">
                            <?php echo e(ucfirst($order->payment_status)); ?>

                        </span>
                    </div>
                </div>
                <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Items</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1"><?php echo e($order->items->count()); ?></p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-purple-100 dark:bg-purple-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Amount</p>
                    <p class="text-2xl font-bold text-slate-900 dark:text-white mt-1">$<?php echo e(number_format($order->total, 2)); ?></p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Order Items -->
            <?php if($order->items->count() > 0): ?>
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                    <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                        <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Order Items</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="bg-slate-50 dark:bg-slate-800/50">
                                <tr>
                                    <th class="px-6 py-4 text-left text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Product</th>
                                    <th class="px-6 py-4 text-center text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Qty</th>
                                    <th class="px-6 py-4 text-right text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Unit Price</th>
                                    <th class="px-6 py-4 text-center text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Billing</th>
                                    <th class="px-6 py-4 text-right text-xs font-semibold text-slate-700 dark:text-slate-300 uppercase tracking-wider">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php $__currentLoopData = $order->items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="px-6 py-4">
                                            <div>
                                                <p class="text-sm font-semibold text-slate-900 dark:text-white"><?php echo e($item->product->name ?? 'Unknown Product'); ?></p>
                                                <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5"><?php echo e($item->description); ?></p>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400 text-center font-medium"><?php echo e($item->quantity); ?></td>
                                        <td class="px-6 py-4 text-sm font-semibold text-slate-900 dark:text-white text-right">$<?php echo e(number_format($item->unit_price, 2)); ?></td>
                                        <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400 text-center"><?php echo e($item->billing_cycle ? ucfirst($item->billing_cycle) : '-'); ?></td>
                                        <td class="px-6 py-4 text-sm font-semibold text-slate-900 dark:text-white text-right">$<?php echo e(number_format($item->amount, 2)); ?></td>
                                    </tr>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Totals Section -->
                    <div class="px-6 py-6 bg-slate-50 dark:bg-slate-800/50 border-t border-slate-200 dark:border-slate-800">
                        <div class="flex justify-end">
                            <div class="w-full md:w-80 space-y-3">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-medium text-slate-600 dark:text-slate-400">Subtotal</span>
                                    <span class="text-sm font-semibold text-slate-900 dark:text-white">$<?php echo e(number_format($order->subtotal, 2)); ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm font-medium text-slate-600 dark:text-slate-400">Tax</span>
                                    <span class="text-sm font-semibold text-slate-900 dark:text-white">$<?php echo e(number_format($order->tax, 2)); ?></span>
                                </div>
                                <div class="border-t border-slate-200 dark:border-slate-700 pt-3 flex justify-between items-center">
                                    <span class="font-semibold text-slate-900 dark:text-white">Total</span>
                                    <span class="text-lg font-bold text-slate-900 dark:text-white">$<?php echo e(number_format($order->total, 2)); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Notes Section -->
            <?php if($order->notes): ?>
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Order Notes</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed"><?php echo e($order->notes); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Customer Card -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h3>
                <div class="space-y-4">
                    <div class="flex items-center gap-3">
                        <div class="w-14 h-14 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                            <?php echo e(strtoupper(substr($order->user->name, 0, 1))); ?>

                        </div>
                        <div class="min-w-0">
                            <p class="font-semibold text-slate-900 dark:text-white truncate"><?php echo e($order->user->name); ?></p>
                            <p class="text-xs text-slate-600 dark:text-slate-400 truncate"><?php echo e($order->user->email); ?></p>
                        </div>
                    </div>
                    <a href="<?php echo e(route('admin.customers.show', $order->user)); ?>" class="block w-full px-4 py-2.5 bg-blue-50 dark:bg-blue-950/30 text-blue-700 dark:text-blue-400 hover:bg-blue-100 dark:hover:bg-blue-950 font-medium rounded-lg transition text-sm text-center border border-blue-200 dark:border-blue-800">
                        View Customer Profile
                    </a>
                </div>
            </div>

            <!-- Invoice Card -->
            <div class="bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
                <div class="flex items-start justify-between mb-4">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Invoice</h3>
                    <div class="w-8 h-8 rounded-lg bg-white dark:bg-slate-900 flex items-center justify-center">
                        <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Invoice will be generated once payment is confirmed.</p>
                <button class="w-full px-4 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 font-medium rounded-lg transition text-sm" disabled>
                    Download Invoice
                </button>
            </div>

            <!-- Services Card -->
            <div class="bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-800/50 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
                <div class="flex items-start justify-between mb-4">
                    <h3 class="text-lg font-semibold text-slate-900 dark:text-white">Services</h3>
                    <div class="w-8 h-8 rounded-lg bg-white dark:bg-slate-900 flex items-center justify-center">
                        <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                        </svg>
                    </div>
                </div>
                <p class="text-sm text-slate-600 dark:text-slate-400 mb-4">Service provisioning will begin after payment.</p>
                <button class="w-full px-4 py-2 bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-50 dark:hover:bg-slate-800 font-medium rounded-lg transition text-sm" disabled>
                    Manage Services
                </button>
            </div>

            <!-- Timeline Card -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Created</p>
                        <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($order->created_at->format('M d, Y')); ?></p>
                        <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($order->created_at->format('h:i A')); ?></p>
                    </div>
                    <div class="border-t border-slate-200 dark:border-slate-700 pt-4">
                        <p class="text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider mb-1">Last Updated</p>
                        <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($order->updated_at->format('M d, Y')); ?></p>
                        <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($order->updated_at->format('h:i A')); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/orders/show.blade.php ENDPATH**/ ?>