<?php $__env->startSection('title', 'Invoice ' . $invoice->invoice_number); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <div>
        <a href="<?php echo e(route('invoices.index')); ?>" class="text-sm font-medium text-blue-600 hover:text-blue-700">← Back to invoices</a>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200 p-8">
        <!-- Invoice Header -->
        <div class="flex items-start justify-between pb-8 border-b border-slate-200">
            <div>
                <h1 class="text-3xl font-bold text-slate-900"><?php echo e($invoice->invoice_number); ?></h1>
                <p class="text-slate-600 mt-1">Invoice Date: <?php echo e($invoice->created_at->format('M d, Y')); ?></p>
            </div>
            <div class="text-right">
                <p class="text-sm font-medium text-slate-600 uppercase mb-2">Status</p>
                <span class="inline-block px-3 py-1 rounded-full text-sm font-medium <?php echo e($invoice->status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'); ?>">
                    <?php echo e(ucfirst($invoice->status)); ?>

                </span>
            </div>
        </div>

        <!-- Customer & Dates -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 py-8 border-b border-slate-200">
            <div>
                <p class="text-xs font-semibold text-slate-600 uppercase mb-2">Bill To</p>
                <p class="font-semibold text-slate-900"><?php echo e($invoice->user->name); ?></p>
                <p class="text-slate-600"><?php echo e($invoice->user->email); ?></p>
                <?php if($invoice->user->company): ?>
                    <p class="text-slate-600"><?php echo e($invoice->user->company); ?></p>
                <?php endif; ?>
            </div>
            <div class="md:text-right">
                <div class="mb-4">
                    <p class="text-xs font-semibold text-slate-600 uppercase mb-1">Due Date</p>
                    <p class="text-lg font-semibold text-slate-900"><?php echo e($invoice->due_date->format('M d, Y')); ?></p>
                </div>
                <?php if($invoice->paid_date): ?>
                    <div>
                        <p class="text-xs font-semibold text-slate-600 uppercase mb-1">Paid On</p>
                        <p class="text-lg font-semibold text-emerald-600"><?php echo e($invoice->paid_date->format('M d, Y')); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Line Items -->
        <div class="py-8 border-b border-slate-200">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-slate-200">
                        <th class="text-left text-xs font-semibold text-slate-900 uppercase pb-3">Description</th>
                        <th class="text-right text-xs font-semibold text-slate-900 uppercase pb-3">Qty</th>
                        <th class="text-right text-xs font-semibold text-slate-900 uppercase pb-3">Price</th>
                        <th class="text-right text-xs font-semibold text-slate-900 uppercase pb-3">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php $__currentLoopData = $invoice->items; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <tr>
                            <td class="py-3 text-slate-900"><?php echo e($item->description); ?></td>
                            <td class="text-right py-3 text-slate-600"><?php echo e($item->quantity); ?></td>
                            <td class="text-right py-3 text-slate-600">$<?php echo e(number_format($item->unit_price, 2)); ?></td>
                            <td class="text-right py-3 font-medium text-slate-900">$<?php echo e(number_format($item->amount, 2)); ?></td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tbody>
            </table>
        </div>

        <!-- Summary -->
        <div class="flex justify-end py-8">
            <div class="w-full md:w-64">
                <div class="flex justify-between mb-3">
                    <span class="text-slate-600">Subtotal</span>
                    <span class="text-slate-900">$<?php echo e(number_format($invoice->subtotal, 2)); ?></span>
                </div>
                <?php if($invoice->tax > 0): ?>
                    <div class="flex justify-between mb-3 pb-3 border-b border-slate-200">
                        <span class="text-slate-600">Tax</span>
                        <span class="text-slate-900">$<?php echo e(number_format($invoice->tax, 2)); ?></span>
                    </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <span class="font-semibold text-slate-900">Total Due</span>
                    <span class="text-2xl font-bold text-slate-900">$<?php echo e(number_format($invoice->total, 2)); ?></span>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <?php if($invoice->notes): ?>
            <div class="pt-8 border-t border-slate-200">
                <p class="text-xs font-semibold text-slate-600 uppercase mb-2">Notes</p>
                <p class="text-slate-700"><?php echo e($invoice->notes); ?></p>
            </div>
        <?php endif; ?>

        <!-- Actions -->
        <?php if(auth()->guard()->check()): ?>
            <?php if(auth()->user()->is_admin || auth()->user()->id === $invoice->user_id): ?>
                <div class="mt-8 flex gap-4">
                    <?php if($invoice->status !== 'paid'): ?>
                        <button onclick="alert('Payment processing will be implemented soon')" class="px-6 py-2.5 rounded-lg bg-emerald-600 text-white font-medium hover:bg-emerald-700 transition-colors">
                            Pay Invoice
                        </button>
                    <?php endif; ?>
                    <button onclick="window.print()" class="px-6 py-2.5 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">
                        Print
                    </button>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/invoices/show.blade.php ENDPATH**/ ?>