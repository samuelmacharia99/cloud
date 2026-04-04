<?php $__env->startSection('title', $order->order_number); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white"><?php echo e($order->order_number); ?></h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Ordered on <?php echo e($order->created_at->format('F d, Y')); ?></p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-medium
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
            <span class="inline-flex items-center px-3 py-1.5 rounded-full text-sm font-medium
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

    <!-- Order Items -->
    <?php if($order->items->count() > 0): ?>
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Order Items</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 dark:border-slate-700">
                        <tr>
                            <th class="text-left py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Product</th>
                            <th class="text-right py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Qty</th>
                            <th class="text-right py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Unit Price</th>
                            <th class="text-left py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Billing Cycle</th>
                            <th class="text-right py-3 px-3 font-medium text-slate-600 dark:text-slate-300">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <?php $__currentLoopData = $order->items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <tr>
                                <td class="py-3 px-3">
                                    <p class="font-medium text-slate-900 dark:text-white"><?php echo e($item->product->name ?? 'Unknown Product'); ?></p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($item->description); ?></p>
                                </td>
                                <td class="py-3 px-3 text-right text-slate-900 dark:text-white"><?php echo e($item->quantity); ?></td>
                                <td class="py-3 px-3 text-right text-slate-900 dark:text-white">$<?php echo e(number_format($item->unit_price, 2)); ?></td>
                                <td class="py-3 px-3 text-slate-600 dark:text-slate-400"><?php echo e($item->billing_cycle ? ucfirst($item->billing_cycle) : '-'); ?></td>
                                <td class="py-3 px-3 text-right font-medium text-slate-900 dark:text-white">$<?php echo e(number_format($item->amount, 2)); ?></td>
                            </tr>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tbody>
                </table>
            </div>

            <!-- Totals -->
            <div class="mt-6 border-t border-slate-200 dark:border-slate-700 pt-6">
                <div class="flex justify-end gap-16">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Subtotal</p>
                        <p class="font-medium text-slate-900 dark:text-white">$<?php echo e(number_format($order->subtotal, 2)); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Tax</p>
                        <p class="font-medium text-slate-900 dark:text-white">$<?php echo e(number_format($order->tax, 2)); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mb-2">Total</p>
                        <p class="font-bold text-lg text-slate-900 dark:text-white">$<?php echo e(number_format($order->total, 2)); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Notes -->
    <?php if($order->notes): ?>
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Notes</h2>
            <p class="text-sm text-slate-600 dark:text-slate-400"><?php echo e($order->notes); ?></p>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Invoice Placeholder -->
        <div class="bg-slate-50 dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Invoice</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400">Invoice will be generated once payment is confirmed.</p>
        </div>

        <!-- Related Services Placeholder -->
        <div class="bg-slate-50 dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Related Services</h3>
            <p class="text-sm text-slate-600 dark:text-slate-400">Service provisioning will begin after payment confirmation.</p>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/orders/show.blade.php ENDPATH**/ ?>