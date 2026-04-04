<?php $__env->startSection('title', 'Edit Invoice'); ?>

<?php $__env->startSection('content'); ?>
<div class="max-w-2xl space-y-8">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Edit Invoice</h1>
        <p class="text-slate-600 mt-1">Update invoice details and status.</p>
    </div>

    <form action="<?php echo e(route('invoices.update', $invoice)); ?>" method="POST" class="bg-white rounded-2xl border border-slate-200 p-8 space-y-6">
        <?php echo csrf_field(); ?>
        <?php echo method_field('PUT'); ?>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Status</label>
            <select name="status" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="unpaid" <?php echo e($invoice->status === 'unpaid' ? 'selected' : ''); ?>>Unpaid</option>
                <option value="paid" <?php echo e($invoice->status === 'paid' ? 'selected' : ''); ?>>Paid</option>
                <option value="overdue" <?php echo e($invoice->status === 'overdue' ? 'selected' : ''); ?>>Overdue</option>
                <option value="cancelled" <?php echo e($invoice->status === 'cancelled' ? 'selected' : ''); ?>>Cancelled</option>
            </select>
            <?php $__errorArgs = ['status'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-red-600 text-sm mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Due Date</label>
            <input type="date" name="due_date" value="<?php echo e($invoice->due_date->format('Y-m-d')); ?>" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            <?php $__errorArgs = ['due_date'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> <p class="text-red-600 text-sm mt-1"><?php echo e($message); ?></p> <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Notes</label>
            <textarea name="notes" rows="3" class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent"><?php echo e($invoice->notes); ?></textarea>
        </div>

        <div class="p-4 rounded-lg bg-slate-100">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-sm text-slate-600">Subtotal</p>
                    <p class="text-lg font-semibold text-slate-900">$<?php echo e(number_format($invoice->subtotal, 2)); ?></p>
                </div>
                <div>
                    <p class="text-sm text-slate-600">Tax</p>
                    <p class="text-lg font-semibold text-slate-900">$<?php echo e(number_format($invoice->tax, 2)); ?></p>
                </div>
                <div class="col-span-2 border-t border-slate-200 pt-3">
                    <p class="text-sm text-slate-600">Total</p>
                    <p class="text-2xl font-bold text-slate-900">$<?php echo e(number_format($invoice->total, 2)); ?></p>
                </div>
            </div>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 transition-colors">
                Update Invoice
            </button>
            <a href="<?php echo e(route('invoices.show', $invoice)); ?>" class="px-6 py-2.5 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/invoices/edit.blade.php ENDPATH**/ ?>