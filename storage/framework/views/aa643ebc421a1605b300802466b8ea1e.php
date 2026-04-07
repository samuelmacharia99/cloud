<?php $__env->startSection('title', 'Deploy New Service'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Deploy New Service</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Choose a service to get started</p>
        </div>
        <a href="<?php echo e(route('customer.cart.index')); ?>" class="relative">
            <svg class="w-6 h-6 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            <?php if($cartCount > 0): ?>
                <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"><?php echo e($cartCount); ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Category Filters -->
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-4">
        <div class="flex gap-2 flex-wrap">
            <a href="<?php echo e(route('customer.deploy-service')); ?>" class="px-4 py-2 rounded-lg font-medium text-sm transition-all <?php echo e(!$selectedType || $selectedType === 'all' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'); ?>">
                All Services
            </a>
            <?php $__currentLoopData = $allTypes; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $type => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <a href="<?php echo e(route('customer.deploy-service', ['type' => $type])); ?>" class="px-4 py-2 rounded-lg font-medium text-sm transition-all <?php echo e($selectedType === $type ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700'); ?>">
                    <?php echo e($label); ?>

                </a>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
        </div>
    </div>

    <!-- Products Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php $__empty_1 = true; $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 hover:border-blue-300 dark:hover:border-blue-700 transition">
                <!-- Type Badge -->
                <div class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 mb-3">
                    <?php echo e(\App\Models\Product::typeLabel($product->type)); ?>

                </div>

                <!-- Name -->
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-2"><?php echo e($product->name); ?></h3>

                <!-- Description -->
                <?php if($product->description): ?>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-4"><?php echo e(Str::limit($product->description, 100)); ?></p>
                <?php endif; ?>

                <!-- Price -->
                <div class="mb-4">
                    <div class="text-2xl font-bold text-slate-900 dark:text-white">
                        Ksh <?php echo e(number_format($product->monthly_price, 0)); ?>

                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">per month</p>
                </div>

                <!-- Features -->
                <?php if($product->features && count($product->features) > 0): ?>
                    <ul class="space-y-2 mb-6">
                        <?php $__currentLoopData = $product->features; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $feature): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <li class="text-sm text-slate-600 dark:text-slate-400 flex items-center gap-2">
                                <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <?php echo e($feature); ?>

                            </li>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </ul>
                <?php endif; ?>

                <!-- Add to Cart Button -->
                <form action="<?php echo e(route('customer.cart.add')); ?>" method="POST" class="space-y-3" x-data="{ cycle: 'monthly', version: <?php echo e($product->containerTemplate && $product->containerTemplate->versions ? "'" . ($product->containerTemplate->versions[0] ?? '') . "'" : 'null'); ?> }">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="type" value="product">
                    <input type="hidden" name="product_id" value="<?php echo e($product->id); ?>">
                    <input type="hidden" name="billing_cycle" x-bind:value="cycle">
                    <?php if($product->containerTemplate && $product->containerTemplate->versions && count($product->containerTemplate->versions) > 0): ?>
                        <input type="hidden" name="version" x-bind:value="version">
                    <?php endif; ?>

                    <?php if($product->containerTemplate && $product->containerTemplate->versions && count($product->containerTemplate->versions) > 0): ?>
                        <div class="flex gap-2">
                            <select x-model="version" class="flex-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm">
                                <?php $__currentLoopData = $product->containerTemplate->versions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $version): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <option value="<?php echo e($version); ?>"><?php echo e($product->containerTemplate->name); ?> <?php echo e($version); ?></option>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="flex gap-2">
                        <select x-model="cycle" class="flex-1 px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm">
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi-annual">Semi-Annual</option>
                            <option value="annual">Annual</option>
                        </select>

                        <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium text-sm transition">
                            Add to Cart
                        </button>
                    </div>
                </form>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="col-span-full text-center py-12">
                <p class="text-slate-500 dark:text-slate-400">No services available in this category</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Domains Section -->
    <div class="bg-gradient-to-r from-purple-50 to-blue-50 dark:from-purple-900/20 dark:to-blue-900/20 rounded-xl border border-purple-200 dark:border-purple-800 p-8 text-center">
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-2">Looking for a Domain?</h2>
        <p class="text-slate-600 dark:text-slate-400 mb-4">Register a new domain or transfer an existing one to Talksasa Cloud</p>
        <a href="<?php echo e(route('customer.domains.index')); ?>" class="inline-flex items-center px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition">
            Browse Domains
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/deploy-service.blade.php ENDPATH**/ ?>