<?php $__env->startSection('title', 'Confirm Techstack'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Confirm Your Techstack</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Review your selection before deploying</p>
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

    <div class="grid md:grid-cols-3 gap-6">
        <!-- Techstack Summary -->
        <div class="md:col-span-2 space-y-6">
            <!-- Language & Database Card -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-6">Your Techstack</h2>

                <div class="grid md:grid-cols-2 gap-6">
                    <!-- Language -->
                    <div class="p-6 rounded-lg bg-slate-50 dark:bg-slate-800 border-2 border-blue-200 dark:border-blue-700">
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-semibold mb-2">Programming Language</p>
                        <h3 class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo e($language->name); ?></h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2"><?php echo e($language->description); ?></p>
                        <?php if($language->versions && count($language->versions) > 0): ?>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <?php $__currentLoopData = $language->versions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $version): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <span class="px-3 py-1 bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300 rounded-full text-sm font-medium">v<?php echo e($version); ?></span>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Database -->
                    <div class="p-6 rounded-lg bg-slate-50 dark:bg-slate-800 border-2 border-green-200 dark:border-green-700">
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-semibold mb-2">Database</p>
                        <h3 class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo e($database->name); ?></h3>
                        <p class="text-sm text-slate-600 dark:text-slate-400 mt-2"><?php echo e($database->description); ?></p>
                        <?php if($database->versions && count($database->versions) > 0): ?>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <?php $__currentLoopData = $database->versions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $version): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <span class="px-3 py-1 bg-green-100 dark:bg-green-900 text-green-700 dark:text-green-300 rounded-full text-sm font-medium">v<?php echo e($version); ?></span>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Hosting Type -->
                <div class="mt-6 p-6 rounded-lg <?php echo e($routing['hosting_type'] === 'directadmin' ? 'bg-blue-50 dark:bg-blue-900/20 border-2 border-blue-200 dark:border-blue-700' : 'bg-purple-50 dark:bg-purple-900/20 border-2 border-purple-200 dark:border-purple-700'); ?>">
                    <div class="flex items-start gap-3">
                        <div class="text-3xl"><?php echo e($routing['hosting_type'] === 'directadmin' ? '🌐' : '🐳'); ?></div>
                        <div>
                            <p class="font-bold <?php echo e($routing['hosting_type'] === 'directadmin' ? 'text-blue-900 dark:text-blue-200' : 'text-purple-900 dark:text-purple-200'); ?>">
                                <?php echo e($routing['hosting_type'] === 'directadmin' ? 'DirectAdmin Shared Hosting' : 'Container Hosting'); ?>

                            </p>
                            <p class="text-sm <?php echo e($routing['hosting_type'] === 'directadmin' ? 'text-blue-700 dark:text-blue-300' : 'text-purple-700 dark:text-purple-300'); ?> mt-1">
                                <?php echo e($routing['hosting_type'] === 'directadmin'
                                    ? 'Your application will run on shared hosting with DirectAdmin control panel access'
                                    : 'Your application will run in a containerized Docker environment for maximum flexibility'); ?>

                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Product Card -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-6">Hosting Plan</h2>

                <div class="space-y-4">
                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-semibold mb-1">Plan Name</p>
                        <h3 class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo e($product->name); ?></h3>
                    </div>

                    <div>
                        <p class="text-sm text-slate-600 dark:text-slate-400 font-semibold mb-1">Description</p>
                        <p class="text-slate-700 dark:text-slate-300"><?php echo e($product->description); ?></p>
                    </div>

                    <?php if($product->features && count($product->features) > 0): ?>
                        <div>
                            <p class="text-sm text-slate-600 dark:text-slate-400 font-semibold mb-2">Features</p>
                            <ul class="space-y-2">
                                <?php $__currentLoopData = $product->features; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $feature): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <li class="text-sm text-slate-700 dark:text-slate-300 flex items-center gap-2">
                                        <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                        </svg>
                                        <?php echo e($feature); ?>

                                    </li>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Pricing & Actions -->
        <div class="md:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-20 space-y-4">
                <div>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mb-1">Monthly Price</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white">
                        Ksh <?php echo e(number_format($product->monthly_price, 0)); ?>

                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">per month</p>
                </div>

                <form action="<?php echo e(route('customer.cart.add')); ?>" method="POST" class="space-y-3" x-data="{ cycle: 'monthly', version: '<?php echo e($language->versions[0] ?? ''); ?>' }">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="type" value="product">
                    <input type="hidden" name="product_id" value="<?php echo e($product->id); ?>">
                    <input type="hidden" name="billing_cycle" x-bind:value="cycle">
                    <?php if($language->versions && count($language->versions) > 0): ?>
                        <input type="hidden" name="version" x-bind:value="version">
                    <?php endif; ?>

                    <!-- Version Selector -->
                    <?php if($language->versions && count($language->versions) > 0): ?>
                        <div>
                            <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Version</label>
                            <select x-model="version" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm">
                                <?php $__currentLoopData = $language->versions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $version): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <option value="<?php echo e($version); ?>">v<?php echo e($version); ?></option>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <!-- Billing Cycle -->
                    <div>
                        <label class="block text-xs font-semibold text-slate-600 dark:text-slate-400 mb-1">Billing Cycle</label>
                        <select x-model="cycle" class="w-full px-3 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white text-sm">
                            <option value="monthly">Monthly</option>
                            <option value="quarterly">Quarterly</option>
                            <option value="semi-annual">Semi-Annual</option>
                            <option value="annual">Annual</option>
                        </select>
                    </div>

                    <!-- Action Buttons -->
                    <div class="space-y-2 pt-2">
                        <button type="submit" class="w-full px-4 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold text-sm transition">
                            Add to Cart
                        </button>
                        <a href="<?php echo e(route('customer.select-techstack')); ?>" class="block w-full px-4 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold text-sm hover:bg-slate-100 dark:hover:bg-slate-800 transition text-center">
                            Change Techstack
                        </a>
                    </div>
                </form>

                <div class="pt-4 border-t border-slate-200 dark:border-slate-700 text-xs text-slate-600 dark:text-slate-400 space-y-2">
                    <p>✓ Automatic scaling</p>
                    <p>✓ SSL included</p>
                    <p>✓ 24/7 support</p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/confirm-techstack.blade.php ENDPATH**/ ?>