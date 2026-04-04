<?php $__env->startSection('title', $product->name); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <!-- Back Button -->
    <div>
        <a href="<?php echo e(route('products.index')); ?>" class="text-sm font-medium text-blue-600 hover:text-blue-700">← Back to products</a>
    </div>

    <!-- Product Header -->
    <div class="bg-white rounded-2xl border border-slate-200 p-8">
        <div class="flex items-start justify-between">
            <div>
                <p class="text-sm font-semibold text-slate-500 uppercase tracking-wider"><?php echo e(ucfirst($product->category)); ?></p>
                <h1 class="text-4xl font-bold text-slate-900 mt-2"><?php echo e($product->name); ?></h1>
                <?php if($product->description): ?>
                    <p class="text-lg text-slate-600 mt-4"><?php echo e($product->description); ?></p>
                <?php endif; ?>
            </div>
            <div>
                <div class="text-right">
                    <div class="flex items-baseline gap-1 justify-end">
                        <span class="text-4xl font-bold text-slate-900">$<?php echo e(number_format($product->price, 2)); ?></span>
                        <span class="text-lg text-slate-600">/<?php echo e(ucfirst($product->billing_cycle)); ?></span>
                    </div>
                    <?php if($product->setup_fee > 0): ?>
                        <p class="text-sm text-slate-600 mt-2">Setup: $<?php echo e(number_format($product->setup_fee, 2)); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="flex gap-4 mt-8">
            <button class="px-8 py-3 rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 transition-colors">
                Order Now
            </button>
            <?php if(auth()->guard()->check()): ?>
                <?php if(auth()->user()->is_admin): ?>
                    <a href="<?php echo e(route('products.edit', $product)); ?>" class="px-8 py-3 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">
                        Edit Product
                    </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Features -->
    <?php if($product->features): ?>
        <div class="bg-white rounded-2xl border border-slate-200 p-8">
            <h2 class="text-2xl font-bold text-slate-900 mb-6">Features</h2>
            <ul class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php $__currentLoopData = $product->features; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $feature): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-emerald-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                        </svg>
                        <span class="text-slate-700"><?php echo e($feature); ?></span>
                    </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Pricing Details -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-6">
            <p class="text-sm font-medium text-slate-600 uppercase">Billing Cycle</p>
            <p class="text-2xl font-bold text-slate-900 mt-2"><?php echo e(ucfirst($product->billing_cycle)); ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-6">
            <p class="text-sm font-medium text-slate-600 uppercase">Recurring Price</p>
            <p class="text-2xl font-bold text-slate-900 mt-2">$<?php echo e(number_format($product->price, 2)); ?></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-6">
            <p class="text-sm font-medium text-slate-600 uppercase">Setup Fee</p>
            <p class="text-2xl font-bold text-slate-900 mt-2">$<?php echo e(number_format($product->setup_fee ?? 0, 2)); ?></p>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/products/show.blade.php ENDPATH**/ ?>