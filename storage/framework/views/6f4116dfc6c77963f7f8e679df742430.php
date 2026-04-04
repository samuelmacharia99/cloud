<?php $__env->startSection('title', 'Payment #' . str_pad($payment->id, 5, '0', STR_PAD_LEFT)); ?>

<?php $__env->startSection('breadcrumb'); ?>
<div class="flex items-center gap-2 text-sm">
    <a href="<?php echo e(route('admin.payments.index')); ?>" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Payments</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">#<?php echo e(str_pad($payment->id, 5, '0', STR_PAD_LEFT)); ?></p>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Payment #<?php echo e(str_pad($payment->id, 5, '0', STR_PAD_LEFT)); ?></h1>
                <p class="text-slate-600 dark:text-slate-400 mt-2"><?php echo e($payment->user->name); ?> • <?php echo e($payment->user->email); ?></p>

                <!-- Status badge -->
                <div class="mt-4">
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

            <!-- Action buttons -->
            <div class="flex items-center gap-2">
                <a href="<?php echo e(route('admin.payments.edit', $payment)); ?>" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition text-sm">
                    Edit Payment
                </a>
            </div>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Payment Details -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Payment Details</h2>
                <div class="grid grid-cols-2 gap-6">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Amount</p>
                        <div class="mt-1">
                            <?php if (isset($component)) { $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.currency-formatter','data' => ['amount' => $payment->amount,'currency' => $payment->currency,'class' => 'text-2xl font-bold']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('currency-formatter'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['amount' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->amount),'currency' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->currency),'class' => 'text-2xl font-bold']); ?>
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
                        <div class="mt-1">
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
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Status</p>
                        <div class="mt-1">
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
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Paid At</p>
                        <p class="text-sm text-slate-900 dark:text-white mt-1">
                            <?php if($payment->paid_at): ?>
                                <?php echo e($payment->paid_at->format('M d, Y h:i A')); ?>

                            <?php else: ?>
                                <span class="text-slate-400">Not yet paid</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if($payment->transaction_reference): ?>
                        <div class="col-span-2">
                            <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Transaction Reference</p>
                            <p class="text-sm text-slate-900 dark:text-white mt-1 font-mono bg-slate-50 dark:bg-slate-800/50 px-3 py-2 rounded border border-slate-200 dark:border-slate-700"><?php echo e($payment->transaction_reference); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Related Invoice -->
            <?php if($payment->invoice): ?>
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Related Invoice</h2>
                    <div class="flex items-center justify-between p-4 border border-slate-200 dark:border-slate-800 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($payment->invoice->invoice_number); ?></p>
                            <div class="flex items-center gap-2 mt-1">
                                <?php if (isset($component)) { $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.currency-formatter','data' => ['amount' => $payment->invoice->total,'currency' => 'KES','showSymbol' => true]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('currency-formatter'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['amount' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->invoice->total),'currency' => 'KES','showSymbol' => true]); ?>
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
                        <a href="<?php echo e(route('admin.invoices.show', $payment->invoice)); ?>" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                            View
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Notes -->
            <?php if($payment->notes): ?>
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-3">Notes</h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 bg-slate-50 dark:bg-slate-800/50 px-3 py-2 rounded border border-slate-200 dark:border-slate-700"><?php echo e($payment->notes); ?></p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Customer</h3>
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white font-semibold text-sm">
                            <?php echo e(strtoupper(substr($payment->user->name, 0, 1))); ?>

                        </div>
                        <div>
                            <p class="font-medium text-slate-900 dark:text-white text-sm"><?php echo e($payment->user->name); ?></p>
                            <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($payment->user->email); ?></p>
                        </div>
                    </div>
                    <a href="<?php echo e(route('admin.customers.show', $payment->user)); ?>" class="block mt-4 px-4 py-2 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-400 hover:bg-blue-200 dark:hover:bg-blue-900 text-sm font-medium rounded-lg transition text-center">
                        View Customer
                    </a>
                </div>
            </div>

            <!-- Payment Method -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Payment Method</h3>
                <div class="flex items-center gap-3">
                    <?php if (isset($component)) { $__componentOriginal2b8ee6ec870934af52873563ceffae5e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2b8ee6ec870934af52873563ceffae5e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.payment-method-icon','data' => ['method' => $payment->payment_method,'class' => 'w-6 h-6']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('payment-method-icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['method' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($payment->payment_method),'class' => 'w-6 h-6']); ?>
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
                    <span class="text-sm text-slate-900 dark:text-white font-medium"><?php echo e($payment->payment_method->label()); ?></span>
                </div>
            </div>

            <!-- Timeline -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Timeline</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Created</p>
                        <p class="text-slate-900 dark:text-white"><?php echo e($payment->created_at->format('M d, Y \a\t h:i A')); ?></p>
                    </div>
                    <div>
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase">Last Updated</p>
                        <p class="text-slate-900 dark:text-white"><?php echo e($payment->updated_at->format('M d, Y \a\t h:i A')); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/payments/show.blade.php ENDPATH**/ ?>