<?php $__env->startSection('title', 'Invoice ' . $invoice->invoice_number); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white"><?php echo e($invoice->invoice_number); ?></h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Invoice dated <?php echo e($invoice->created_at->format('F d, Y')); ?></p>
        </div>
        <span class="inline-flex items-center px-4 py-2 rounded-full text-sm font-medium
            <?php if($invoice->status === 'paid'): ?>
                bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
            <?php elseif($invoice->status === 'unpaid'): ?>
                bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300
            <?php elseif($invoice->status === 'draft'): ?>
                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
            <?php elseif($invoice->status === 'overdue'): ?>
                bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
            <?php elseif($invoice->status === 'cancelled'): ?>
                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
            <?php else: ?>
                bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
            <?php endif; ?>
        ">
            <?php echo e(ucfirst($invoice->status)); ?>

        </span>
    </div>

    <!-- Invoice Card -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <!-- Top Stripe -->
        <div class="h-1 bg-gradient-to-r
            <?php if($invoice->status === 'paid'): ?>
                from-emerald-500 to-emerald-600
            <?php elseif($invoice->status === 'unpaid'): ?>
                from-amber-500 to-amber-600
            <?php elseif($invoice->status === 'draft'): ?>
                from-slate-500 to-slate-600
            <?php elseif($invoice->status === 'overdue'): ?>
                from-red-500 to-red-600
            <?php else: ?>
                from-slate-500 to-slate-600
            <?php endif; ?>
        "></div>

        <div class="p-8 md:p-12">
            <!-- Header Section -->
            <div class="mb-8 pb-8 border-b border-slate-200 dark:border-slate-700">
                <div class="flex items-start justify-between">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-900 dark:text-white">INVOICE</h2>
                    </div>
                    <div class="text-right text-sm">
                        <p class="text-slate-600 dark:text-slate-400 mb-4">Invoice Number</p>
                        <p class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo e($invoice->invoice_number); ?></p>
                    </div>
                </div>
            </div>

            <!-- Invoice Metadata -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                <!-- Bill To -->
                <div>
                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-3">Bill To</p>
                    <p class="text-lg font-medium text-slate-900 dark:text-white"><?php echo e($invoice->user->name); ?></p>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1"><?php echo e($invoice->user->email); ?></p>
                    <?php if($invoice->user->address): ?>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2"><?php echo e($invoice->user->address); ?></p>
                        <?php if($invoice->user->city): ?>
                            <p class="text-sm text-slate-600 dark:text-slate-400"><?php echo e($invoice->user->city); ?><?php echo e($invoice->user->postal_code ? ', ' . $invoice->user->postal_code : ''); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- Invoice Details -->
                <div class="text-right">
                    <div class="mb-4">
                        <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-1">Invoice Date</p>
                        <p class="text-sm text-slate-900 dark:text-white"><?php echo e($invoice->created_at->format('F d, Y')); ?></p>
                    </div>
                    <?php if($invoice->due_date): ?>
                        <div>
                            <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-1">Due Date</p>
                            <p class="text-sm text-slate-900 dark:text-white"><?php echo e($invoice->due_date->format('F d, Y')); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Line Items -->
            <?php if($invoice->items->count() > 0): ?>
                <div class="mb-8">
                    <table class="w-full mb-4">
                        <thead>
                            <tr class="border-b-2 border-slate-300 dark:border-slate-600">
                                <th class="text-left py-3 px-3 text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Description</th>
                                <th class="text-right py-3 px-3 text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Qty</th>
                                <th class="text-right py-3 px-3 text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Unit Price</th>
                                <th class="text-right py-3 px-3 text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $__currentLoopData = $invoice->items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <tr class="border-b border-slate-200 dark:border-slate-700">
                                    <td class="py-3 px-3">
                                        <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($item->product->name ?? 'Unknown Product'); ?></p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($item->description); ?></p>
                                    </td>
                                    <td class="py-3 px-3 text-right text-sm text-slate-900 dark:text-white"><?php echo e($item->quantity); ?></td>
                                    <td class="py-3 px-3 text-right text-sm text-slate-900 dark:text-white">$<?php echo e(number_format($item->unit_price, 2)); ?></td>
                                    <td class="py-3 px-3 text-right text-sm font-medium text-slate-900 dark:text-white">$<?php echo e(number_format($item->amount, 2)); ?></td>
                                </tr>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- Totals -->
            <div class="flex justify-end mb-8">
                <div class="w-full md:w-80">
                    <div class="flex justify-between py-2 border-b border-slate-200 dark:border-slate-700 mb-2">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Subtotal</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">$<?php echo e(number_format($invoice->subtotal, 2)); ?></span>
                    </div>
                    <div class="flex justify-between py-2 border-b border-slate-200 dark:border-slate-700 mb-3">
                        <span class="text-sm text-slate-600 dark:text-slate-400">Tax</span>
                        <span class="text-sm font-medium text-slate-900 dark:text-white">$<?php echo e(number_format($invoice->tax, 2)); ?></span>
                    </div>
                    <div class="flex justify-between py-3 bg-slate-50 dark:bg-slate-800 px-3 rounded">
                        <span class="text-base font-bold text-slate-900 dark:text-white">Total Due</span>
                        <span class="text-lg font-bold text-slate-900 dark:text-white">$<?php echo e(number_format($invoice->total, 2)); ?></span>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <?php if($invoice->notes): ?>
                <div class="mb-8 pb-8 border-t border-slate-200 dark:border-slate-700 pt-8">
                    <p class="text-xs font-semibold text-slate-600 dark:text-slate-400 uppercase mb-2">Notes</p>
                    <p class="text-sm text-slate-600 dark:text-slate-400"><?php echo e($invoice->notes); ?></p>
                </div>
            <?php endif; ?>

            <!-- Payment Status & Actions -->
            <div class="border-t border-slate-200 dark:border-slate-700 pt-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <?php if($invoice->status !== 'paid'): ?>
                        <button disabled class="px-6 py-3 bg-blue-600 text-white font-medium rounded-lg transition opacity-50 cursor-not-allowed" title="Payment functionality coming soon">
                            Pay Now
                        </button>
                    <?php else: ?>
                        <button disabled class="px-6 py-3 bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300 font-medium rounded-lg" title="This invoice has been paid">
                            Paid
                        </button>
                    <?php endif; ?>
                    <button disabled class="px-6 py-3 bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 font-medium rounded-lg transition opacity-50 cursor-not-allowed" title="Download functionality coming soon">
                        Download PDF
                    </button>
                </div>

                <!-- Payment History -->
                <?php if($invoice->payments->count() > 0): ?>
                    <div class="mt-8 pt-8 border-t border-slate-200 dark:border-slate-700">
                        <p class="text-sm font-semibold text-slate-900 dark:text-white mb-3">Payment History</p>
                        <div class="space-y-2">
                            <?php $__currentLoopData = $invoice->payments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $payment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <div class="flex items-center justify-between p-3 bg-slate-50 dark:bg-slate-800 rounded">
                                    <div>
                                        <p class="text-sm font-medium text-slate-900 dark:text-white">$<?php echo e(number_format($payment->amount, 2)); ?></p>
                                        <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e(ucfirst($payment->gateway)); ?> • <?php echo e($payment->created_at->format('M d, Y')); ?></p>
                                    </div>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                        <?php if($payment->status === 'completed'): ?>
                                            bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300
                                        <?php elseif($payment->status === 'pending'): ?>
                                            bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300
                                        <?php elseif($payment->status === 'failed'): ?>
                                            bg-red-100 dark:bg-red-950 text-red-700 dark:text-red-300
                                        <?php else: ?>
                                            bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400
                                        <?php endif; ?>
                                    ">
                                        <?php echo e(ucfirst($payment->status)); ?>

                                    </span>
                                </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/invoices/show.blade.php ENDPATH**/ ?>