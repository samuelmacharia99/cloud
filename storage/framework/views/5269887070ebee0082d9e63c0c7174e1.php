<?php $__env->startSection('title', 'My Payments'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">My Payments</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Track your payment history and receipts.</p>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Payments</p>
            <p class="text-2xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($payments->total()); ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Paid</p>
            <div class="mt-2">
                <?php if (isset($component)) { $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.currency-formatter','data' => ['amount' => $payments->sum('amount'),'currency' => 'KES','class' => 'text-2xl font-bold']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('currency-formatter'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['amount' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payments->sum('amount')),'currency' => 'KES','class' => 'text-2xl font-bold']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf)): ?>
<?php $attributes = $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf; ?>
<?php unset($__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf)): ?>
<?php $component = $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf; ?>
<?php unset($__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf); ?>
<?php endif; ?>
            </div>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Completed</p>
            <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-2"><?php echo e($payments->where('status', 'completed')->count()); ?></p>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="border-b border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Amount</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Method</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Invoice</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Date</th>
                        <th class="px-6 py-4 text-center text-sm font-semibold text-slate-900 dark:text-white">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php $__empty_1 = true; $__currentLoopData = $payments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $payment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                            <td class="px-6 py-4 text-sm font-medium text-slate-900 dark:text-white">
                                <?php if (isset($component)) { $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.currency-formatter','data' => ['amount' => $payment->amount,'currency' => $payment->currency]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('currency-formatter'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['amount' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->amount),'currency' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->currency)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf)): ?>
<?php $attributes = $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf; ?>
<?php unset($__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf)): ?>
<?php $component = $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf; ?>
<?php unset($__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf); ?>
<?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if (isset($component)) { $__componentOriginalb5baa7884180f9ce58dcb852289fd127 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb5baa7884180f9ce58dcb852289fd127 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.payment-badge','data' => ['method' => $payment->payment_method]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('payment-badge'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['method' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->payment_method)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb5baa7884180f9ce58dcb852289fd127)): ?>
<?php $attributes = $__attributesOriginalb5baa7884180f9ce58dcb852289fd127; ?>
<?php unset($__attributesOriginalb5baa7884180f9ce58dcb852289fd127); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb5baa7884180f9ce58dcb852289fd127)): ?>
<?php $component = $__componentOriginalb5baa7884180f9ce58dcb852289fd127; ?>
<?php unset($__componentOriginalb5baa7884180f9ce58dcb852289fd127); ?>
<?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                <?php if($payment->invoice): ?>
                                    <a href="<?php echo e(route('customer.invoices.show', $payment->invoice)); ?>" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                        <?php echo e($payment->invoice->invoice_number); ?>

                                    </a>
                                <?php else: ?>
                                    <span class="text-slate-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if (isset($component)) { $__componentOriginal8c81617a70e11bcf247c4db924ab1b62 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8c81617a70e11bcf247c4db924ab1b62 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.status-badge','data' => ['status' => $payment->status,'type' => 'payment']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('status-badge'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['status' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->status),'type' => 'payment']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8c81617a70e11bcf247c4db924ab1b62)): ?>
<?php $attributes = $__attributesOriginal8c81617a70e11bcf247c4db924ab1b62; ?>
<?php unset($__attributesOriginal8c81617a70e11bcf247c4db924ab1b62); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8c81617a70e11bcf247c4db924ab1b62)): ?>
<?php $component = $__componentOriginal8c81617a70e11bcf247c4db924ab1b62; ?>
<?php unset($__componentOriginal8c81617a70e11bcf247c4db924ab1b62); ?>
<?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                <?php echo e($payment->created_at->format('M d, Y')); ?>

                            </td>
                            <td class="px-6 py-4 text-center">
                                <a href="<?php echo e(route('customer.payments.show', $payment)); ?>" class="text-blue-600 dark:text-blue-400 hover:underline text-sm font-medium">
                                    View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <div class="space-y-2">
                                    <svg class="mx-auto w-12 h-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    <p class="text-slate-600 dark:text-slate-400 font-medium">No payments yet</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if($payments->hasPages()): ?>
            <div class="border-t border-slate-200 dark:border-slate-800 px-6 py-4">
                <?php echo e($payments->links()); ?>

            </div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/payments/index.blade.php ENDPATH**/ ?>