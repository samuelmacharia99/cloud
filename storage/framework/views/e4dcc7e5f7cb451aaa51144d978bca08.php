<?php $__env->startSection('title', 'My Orders'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Orders</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">View and manage your orders.</p>
    </div>

    <!-- Orders Table -->
    <?php if($orders->count() > 0): ?>
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                        <tr>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Order #</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Items</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Total</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Payment</th>
                            <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                            <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php $__currentLoopData = $orders; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $order): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white"><?php echo e($order->order_number); ?></td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400"><?php echo e($order->created_at->format('M d, Y')); ?></td>
                                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400"><?php echo e($order->items_count ?? $order->items->count()); ?></td>
                                <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white text-right">$<?php echo e(number_format($order->total, 2)); ?></td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
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
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
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
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <a href="<?php echo e(route('customer.orders.show', $order)); ?>" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <div class="mt-6">
            <?php echo e($orders->links()); ?>

        </div>
    <?php else: ?>
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-12 text-center">
            <svg class="w-16 h-16 text-slate-300 dark:text-slate-600 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
            </svg>
            <p class="text-slate-600 dark:text-slate-400">You don't have any orders yet</p>
        </div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/orders/index.blade.php ENDPATH**/ ?>