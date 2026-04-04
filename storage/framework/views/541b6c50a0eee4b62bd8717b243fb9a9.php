<?php $__env->startSection('title', 'Payment Receipt'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Payment Receipt</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Payment details and status</p>
    </div>

    <!-- Main Content -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Left Column (Main Content) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Hero Section: Amount & Status -->
            <div class="bg-gradient-to-br from-blue-600 to-blue-700 dark:from-blue-900 dark:to-blue-950 rounded-xl p-8 text-white">
                <p class="text-sm font-medium text-blue-100 uppercase tracking-wide">Payment Amount</p>
                <div class="mt-3">
                    <span class="text-4xl font-bold">
                        <?php if($payment->currency === 'KES'): ?>
                            Ksh
                        <?php elseif($payment->currency === 'USD'): ?>
                            $
                        <?php elseif($payment->currency === 'GBP'): ?>
                            £
                        <?php else: ?>
                            <?php echo e($payment->currency); ?>

                        <?php endif; ?>
                        <?php echo e(number_format($payment->amount, 2)); ?>

                    </span>
                </div>
                <div class="mt-6 pt-6 border-t border-blue-400/30">
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

            <!-- Payment Details Card -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Payment Details</h2>
                <div class="space-y-4">
                    <!-- Method -->
                    <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 text-sm font-medium">Payment Method</span>
                        <div>
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

                    <!-- Transaction Reference -->
                    <?php if($payment->transaction_reference): ?>
                        <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                            <span class="text-slate-600 dark:text-slate-400 text-sm font-medium">Transaction ID</span>
                            <code class="text-sm text-slate-900 dark:text-white font-mono bg-slate-50 dark:bg-slate-800 px-3 py-1 rounded">
                                <?php echo e($payment->transaction_reference); ?>

                            </code>
                        </div>
                    <?php endif; ?>

                    <!-- Currency -->
                    <div class="flex items-center justify-between pb-4 border-b border-slate-200 dark:border-slate-800">
                        <span class="text-slate-600 dark:text-slate-400 text-sm font-medium">Currency</span>
                        <span class="text-slate-900 dark:text-white font-medium"><?php echo e($payment->currency); ?></span>
                    </div>

                    <!-- Paid Date -->
                    <div class="flex items-center justify-between">
                        <span class="text-slate-600 dark:text-slate-400 text-sm font-medium">Date Processed</span>
                        <span class="text-slate-900 dark:text-white">
                            <?php if($payment->paid_at): ?>
                                <?php echo e($payment->paid_at->format('M d, Y \a\t h:i A')); ?>

                            <?php else: ?>
                                <span class="text-slate-400">Not yet paid</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Related Invoice (if exists) -->
            <?php if($payment->invoice): ?>
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Related Invoice</h2>
                    <div class="flex items-center justify-between p-4 border border-slate-200 dark:border-slate-800 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white"><?php echo e($payment->invoice->invoice_number); ?></p>
                            <div class="flex items-center gap-2 mt-1">
                                <?php if (isset($component)) { $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.currency-formatter','data' => ['amount' => $payment->invoice->total,'currency' => $payment->invoice->currency ?? 'KES','showSymbol' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('currency-formatter'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['amount' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->invoice->total),'currency' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->invoice->currency ?? 'KES'),'showSymbol' => true]); ?>
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
                        <a href="<?php echo e(route('customer.invoices.show', $payment->invoice)); ?>" class="text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">
                            View Invoice →
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Notes (if exists) -->
            <?php if($payment->notes): ?>
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Notes</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 whitespace-pre-wrap"><?php echo e($payment->notes); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right Sidebar -->
        <div class="space-y-6">
            <!-- Quick Info Card -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white uppercase text-xs tracking-wide mb-4">Payment Summary</h3>
                <div class="space-y-4">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Status</p>
                        <div class="mt-2">
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
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Amount</p>
                        <p class="text-lg font-bold text-slate-900 dark:text-white mt-1">
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
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Method</p>
                        <div class="mt-2 flex items-center gap-2">
                            <?php if (isset($component)) { $__componentOriginal2b8ee6ec870934af52873563ceffae5e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2b8ee6ec870934af52873563ceffae5e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.payment-method-icon','data' => ['method' => $payment->payment_method,'class' => 'w-5 h-5']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('payment-method-icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['method' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->payment_method),'class' => 'w-5 h-5']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2b8ee6ec870934af52873563ceffae5e)): ?>
<?php $attributes = $__attributesOriginal2b8ee6ec870934af52873563ceffae5e; ?>
<?php unset($__attributesOriginal2b8ee6ec870934af52873563ceffae5e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2b8ee6ec870934af52873563ceffae5e)): ?>
<?php $component = $__componentOriginal2b8ee6ec870934af52873563ceffae5e; ?>
<?php unset($__componentOriginal2b8ee6ec870934af52873563ceffae5e); ?>
<?php endif; ?>
                            <span class="text-sm text-slate-900 dark:text-white"><?php echo e($payment->payment_method->label()); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-white uppercase text-xs tracking-wide mb-4">Timeline</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-slate-900 dark:text-white mt-1"><?php echo e($payment->created_at->format('M d, Y')); ?></p>
                    </div>
                    <?php if($payment->paid_at): ?>
                        <div>
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Paid At</p>
                            <p class="text-slate-900 dark:text-white mt-1"><?php echo e($payment->paid_at->format('M d, Y')); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Actions -->
            <div class="space-y-2">
                <button onclick="window.print()" class="w-full px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-900 dark:text-white font-medium rounded-lg transition-colors text-sm">
                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    Print Receipt
                </button>
                <a href="<?php echo e(route('customer.payments.index')); ?>" class="block w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition-colors text-sm text-center">
                    Back to Payments
                </a>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/payments/show.blade.php ENDPATH**/ ?>