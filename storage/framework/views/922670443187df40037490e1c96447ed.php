<?php $__env->startSection('title', 'Available Domains'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Available Domain Extensions</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-2">Register your domain with our extensive selection of extensions</p>
    </div>

    <!-- Extension Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php $__empty_1 = true; $__currentLoopData = $extensions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $extension): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden hover:shadow-lg transition-shadow">
                <!-- Header -->
                <div class="px-6 py-4 bg-gradient-to-r from-blue-50 to-blue-100 dark:from-blue-950 dark:to-blue-900 border-b border-slate-200 dark:border-slate-700">
                    <h2 class="text-2xl font-bold text-slate-900 dark:text-white"><?php echo e($extension->extension); ?></h2>
                    <p class="text-sm text-slate-600 dark:text-slate-400 mt-1"><?php echo e($extension->description); ?></p>
                </div>

                <!-- Pricing Table -->
                <div class="p-6 space-y-3">
                    <div class="space-y-2">
                        <p class="text-xs uppercase font-semibold text-slate-600 dark:text-slate-400">Pricing</p>

                        <?php
                            $pricing1 = $extension->getRetailPricing(1);
                            $pricing2 = $extension->getRetailPricing(2);
                            $pricing3 = $extension->getRetailPricing(3);
                            $pricing5 = $extension->getRetailPricing(5);
                            $pricing10 = $extension->getRetailPricing(10);
                        ?>

                        <div class="grid grid-cols-2 gap-2">
                            <?php if($pricing1): ?>
                                <div class="p-2 bg-slate-50 dark:bg-slate-800 rounded text-center">
                                    <p class="text-xs text-slate-600 dark:text-slate-400">1 Year</p>
                                    <p class="text-lg font-bold text-slate-900 dark:text-white">$<?php echo e(number_format($pricing1->price, 2)); ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if($pricing2): ?>
                                <div class="p-2 bg-slate-50 dark:bg-slate-800 rounded text-center">
                                    <p class="text-xs text-slate-600 dark:text-slate-400">2 Years</p>
                                    <p class="text-lg font-bold text-slate-900 dark:text-white">$<?php echo e(number_format($pricing2->price, 2)); ?></p>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-0.5">Save 5%</p>
                                </div>
                            <?php endif; ?>
                            <?php if($pricing3): ?>
                                <div class="p-2 bg-slate-50 dark:bg-slate-800 rounded text-center">
                                    <p class="text-xs text-slate-600 dark:text-slate-400">3 Years</p>
                                    <p class="text-lg font-bold text-slate-900 dark:text-white">$<?php echo e(number_format($pricing3->price, 2)); ?></p>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-0.5">Save 7%</p>
                                </div>
                            <?php endif; ?>
                            <?php if($pricing5): ?>
                                <div class="p-2 bg-slate-50 dark:bg-slate-800 rounded text-center">
                                    <p class="text-xs text-slate-600 dark:text-slate-400">5 Years</p>
                                    <p class="text-lg font-bold text-slate-900 dark:text-white">$<?php echo e(number_format($pricing5->price, 2)); ?></p>
                                    <p class="text-xs text-emerald-600 dark:text-emerald-400 mt-0.5">Save 10%</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Features -->
                    <div class="pt-3 border-t border-slate-200 dark:border-slate-700">
                        <p class="text-xs uppercase font-semibold text-slate-600 dark:text-slate-400 mb-2">Features</p>
                        <div class="space-y-1.5">
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                                <span class="text-sm text-slate-700 dark:text-slate-300">Free DNS Management</span>
                            </div>
                            <?php if($extension->auto_renewal): ?>
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                    </svg>
                                    <span class="text-sm text-slate-700 dark:text-slate-300">Auto-Renewal Available</span>
                                </div>
                            <?php endif; ?>
                            <div class="flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                </svg>
                                <span class="text-sm text-slate-700 dark:text-slate-300">Registrar: <?php echo e($extension->registrar); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Register Button -->
                    <button class="w-full mt-4 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                        Register Domain
                    </button>
                </div>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="col-span-full bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-12 text-center">
                <p class="text-slate-600 dark:text-slate-400">No domain extensions available at this time.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/domains/available.blade.php ENDPATH**/ ?>