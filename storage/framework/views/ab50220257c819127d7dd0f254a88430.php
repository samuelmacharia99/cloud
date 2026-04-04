<?php $__env->startSection('title', 'Products'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Products & Services</h1>
            <p class="text-slate-600 mt-1">Choose the perfect plan for your needs.</p>
        </div>
        <?php if(auth()->guard()->check()): ?>
            <?php if(auth()->user()->is_admin): ?>
                <a href="<?php echo e(route('products.create')); ?>" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors">
                    + Add Product
                </a>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Products Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php $__empty_1 = true; $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden hover:border-slate-300 transition-all hover:shadow-lg">
                <div class="p-6 space-y-4">
                    <div>
                        <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider"><?php echo e(ucfirst($product->category)); ?></p>
                        <h3 class="text-xl font-bold text-slate-900 mt-1"><?php echo e($product->name); ?></h3>
                    </div>

                    <div>
                        <div class="flex items-baseline gap-1">
                            <span class="text-3xl font-bold text-slate-900">$<?php echo e(number_format($product->price, 2)); ?></span>
                            <span class="text-sm text-slate-600">/<?php echo e(ucfirst($product->billing_cycle)); ?></span>
                        </div>
                        <?php if($product->setup_fee > 0): ?>
                            <p class="text-sm text-slate-600 mt-1">Setup fee: $<?php echo e(number_format($product->setup_fee, 2)); ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if($product->description): ?>
                        <p class="text-sm text-slate-600"><?php echo e(Str::limit($product->description, 100)); ?></p>
                    <?php endif; ?>

                    <?php if($product->features): ?>
                        <ul class="space-y-2">
                            <?php $__currentLoopData = array_slice($product->features, 0, 3); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $feature): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <li class="flex items-center gap-2 text-sm text-slate-600">
                                    <svg class="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    <?php echo e($feature); ?>

                                </li>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div class="p-6 border-t border-slate-200 flex gap-3">
                    <a href="<?php echo e(route('products.show', $product)); ?>" class="flex-1 text-center px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium hover:bg-slate-50 transition-colors">
                        Learn more
                    </a>
                    <a href="#" class="flex-1 text-center px-4 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition-colors">
                        Select
                    </a>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="col-span-full py-12 text-center">
                <p class="text-slate-500">No products available</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if($products->hasPages()): ?>
        <div class="flex items-center justify-center gap-2">
            <?php echo e($products->links()); ?>

        </div>
    <?php endif; ?>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/products/index.blade.php ENDPATH**/ ?>