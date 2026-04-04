<?php $__env->startSection('title', 'Edit Payment #' . str_pad($payment->id, 5, '0', STR_PAD_LEFT)); ?>

<?php $__env->startSection('breadcrumb'); ?>
<div class="flex items-center gap-2 text-sm">
    <a href="<?php echo e(route('admin.payments.index')); ?>" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Payments</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="<?php echo e(route('admin.payments.show', $payment)); ?>" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">#<?php echo e(str_pad($payment->id, 5, '0', STR_PAD_LEFT)); ?></a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Edit</p>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="max-w-2xl">
    <!-- Header -->
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Update Payment</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2">Update payment status and notes. Amount and method cannot be changed.</p>
    </div>

    <!-- Form Card -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
        <form action="<?php echo e(route('admin.payments.update', $payment)); ?>" method="POST" class="space-y-6">
            <?php echo csrf_field(); ?>
            <?php echo method_field('PUT'); ?>

            <!-- Read-Only Details -->
            <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Payment Details (Read-Only)</h2>
                <div class="grid grid-cols-2 gap-6 bg-slate-50 dark:bg-slate-800/30 p-4 rounded-lg">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Amount</p>
                        <div class="mt-2">
                            <?php if (isset($component)) { $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.currency-formatter','data' => ['amount' => $payment->amount,'currency' => $payment->currency,'class' => 'text-lg font-semibold']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('currency-formatter'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['amount' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->amount),'currency' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->currency),'class' => 'text-lg font-semibold']); ?>
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
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Method</p>
                        <div class="mt-2">
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
                        </div>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Transaction Reference</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-2 font-mono"><?php echo e($payment->transaction_reference ?? 'None'); ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Customer</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-2"><?php echo e($payment->user->name); ?></p>
                    </div>
                </div>
            </div>

            <!-- Status Update Section -->
            <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Update Status</h2>
                <div class="space-y-4">
                    <div>
                        <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Current Status</p>
                        <div class="p-3 bg-slate-50 dark:bg-slate-800/50 rounded-lg">
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
                        </div>
                    </div>

                    <?php if (isset($component)) { $__componentOriginal67ad07a4b593e690d435fee92e6413bb = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal67ad07a4b593e690d435fee92e6413bb = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.form-select','data' => ['label' => 'New Status','name' => 'status','options' => $statuses,'value' => $payment->status->value]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('form-select'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['label' => 'New Status','name' => 'status','options' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($statuses),'value' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->status->value)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal67ad07a4b593e690d435fee92e6413bb)): ?>
<?php $attributes = $__attributesOriginal67ad07a4b593e690d435fee92e6413bb; ?>
<?php unset($__attributesOriginal67ad07a4b593e690d435fee92e6413bb); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal67ad07a4b593e690d435fee92e6413bb)): ?>
<?php $component = $__componentOriginal67ad07a4b593e690d435fee92e6413bb; ?>
<?php unset($__componentOriginal67ad07a4b593e690d435fee92e6413bb); ?>
<?php endif; ?>

                    <div class="p-3 bg-blue-50 dark:bg-blue-950/30 border border-blue-200 dark:border-blue-900 rounded-lg text-sm text-blue-900 dark:text-blue-200">
                        <p><strong>Allowed transitions:</strong></p>
                        <ul class="mt-2 ml-4 space-y-1 text-xs list-disc">
                            <li>Pending → Completed or Failed</li>
                            <li>Completed → Reversed</li>
                            <li>Failed and Reversed are final states</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Notes Section -->
            <div class="pb-6">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                    Notes <span class="text-slate-400 text-xs">(optional)</span>
                </label>
                <textarea
                    name="notes"
                    rows="4"
                    placeholder="Add or update notes about this payment..."
                    class="w-full px-4 py-2.5 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800 text-slate-900 dark:text-white placeholder-slate-500 dark:placeholder-slate-400 focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                ><?php echo e(old('notes', $payment->notes)); ?></textarea>
            </div>

            <!-- Related Invoice (if exists) -->
            <?php if($payment->invoice): ?>
                <div class="border-b border-slate-200 dark:border-slate-800 pb-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Related Invoice</h2>
                    <div class="p-4 border border-slate-200 dark:border-slate-800 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="font-medium text-slate-900 dark:text-white"><?php echo e($payment->invoice->invoice_number); ?></p>
                                <div class="flex items-center gap-2 mt-1 text-sm">
                                    <?php if (isset($component)) { $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.currency-formatter','data' => ['amount' => $payment->invoice->total,'currency' => 'KES']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('currency-formatter'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['amount' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->invoice->total),'currency' => 'KES']); ?>
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
                                    <span class="text-slate-400">•</span>
                                    <?php if (isset($component)) { $__componentOriginal8c81617a70e11bcf247c4db924ab1b62 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8c81617a70e11bcf247c4db924ab1b62 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.status-badge','data' => ['status' => $payment->invoice->status,'type' => 'invoice']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('status-badge'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['status' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->invoice->status),'type' => 'invoice']); ?>
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
                                </div>
                            </div>
                            <a href="<?php echo e(route('admin.invoices.show', $payment->invoice)); ?>" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">
                                View Invoice →
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Action Buttons -->
            <div class="flex items-center gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
                <button type="submit" class="px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors">
                    <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Update Payment
                </button>
                <a href="<?php echo e(route('admin.payments.show', $payment)); ?>" class="px-6 py-2.5 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 font-medium rounded-lg transition-colors">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/payments/edit.blade.php ENDPATH**/ ?>