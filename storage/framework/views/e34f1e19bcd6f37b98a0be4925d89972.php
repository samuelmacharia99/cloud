<?php $__env->startSection('title', 'Reseller Package'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Limit Exceeded Warning Banner -->
    <?php if(session('limit_exceeded')): ?>
        <div class="p-4 bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 rounded-lg">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                </svg>
                <div>
                    <h3 class="font-semibold text-red-900 dark:text-red-100">Package Limits Exceeded</h3>
                    <p class="text-sm text-red-700 dark:text-red-300 mt-1">You have reached your package limits. Upgrade to continue adding services or customers.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- No Package Alert -->
    <?php if(!$user->hasResellerPackage()): ?>
        <div class="p-4 bg-amber-50 dark:bg-amber-950 border border-amber-200 dark:border-amber-800 rounded-lg">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
                <div>
                    <h3 class="font-semibold text-amber-900 dark:text-amber-100">No Active Plan</h3>
                    <p class="text-sm text-amber-700 dark:text-amber-300 mt-1">You don't have a package yet. Select one below to start managing services and customers.</p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Current Plan Card -->
    <?php if($user->resellerPackage): ?>
        <div class="bg-gradient-to-br from-purple-50 to-purple-100 dark:from-purple-950 dark:to-purple-900 rounded-xl border border-purple-200 dark:border-purple-800 p-8">
            <div class="max-w-3xl">
                <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-2"><?php echo e($user->resellerPackage->name); ?></h2>
                <p class="text-slate-600 dark:text-slate-300 mb-6">
                    <span class="inline-block px-3 py-1 bg-purple-200 dark:bg-purple-800 text-purple-900 dark:text-purple-100 rounded-lg text-sm font-medium">
                        <?php echo e(ucfirst($user->resellerPackage->billing_cycle)); ?> Plan
                    </span>
                </p>

                <!-- Usage Meters -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Service Slots -->
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="font-medium text-slate-900 dark:text-white">Service Slots</span>
                            <span class="text-sm text-slate-600 dark:text-slate-400"><?php echo e($currentServices); ?> / <?php echo e($user->resellerPackage->storage_space); ?></span>
                        </div>
                        <?php
                            $servicePct = $user->resellerPackage->storage_space > 0
                                ? min(100, round(($currentServices / $user->resellerPackage->storage_space) * 100))
                                : 0;
                            $serviceColor = $servicePct >= 90 ? 'bg-red-500' : ($servicePct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                        ?>
                        <div class="w-full h-3 bg-slate-300 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="<?php echo e($serviceColor); ?> h-3 rounded-full transition-all" style="width: <?php echo e($servicePct); ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Active services managed by your account</p>
                    </div>

                    <!-- Customers -->
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="font-medium text-slate-900 dark:text-white">Customers</span>
                            <span class="text-sm text-slate-600 dark:text-slate-400"><?php echo e($currentCustomers); ?> / <?php echo e($user->resellerPackage->max_users); ?></span>
                        </div>
                        <?php
                            $customerPct = $user->resellerPackage->max_users > 0
                                ? min(100, round(($currentCustomers / $user->resellerPackage->max_users) * 100))
                                : 0;
                            $customerColor = $customerPct >= 90 ? 'bg-red-500' : ($customerPct >= 75 ? 'bg-amber-500' : 'bg-emerald-500');
                        ?>
                        <div class="w-full h-3 bg-slate-300 dark:bg-slate-700 rounded-full overflow-hidden">
                            <div class="<?php echo e($customerColor); ?> h-3 rounded-full transition-all" style="width: <?php echo e($customerPct); ?>%"></div>
                        </div>
                        <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Distinct customers under your reseller account</p>
                    </div>
                </div>

                <!-- Subscription Info -->
                <div class="mt-6 pt-6 border-t border-purple-300 dark:border-purple-700">
                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        <span class="font-medium">Subscribed since</span>
                        <?php echo e($user->package_subscribed_at?->format('M d, Y') ?? 'Unknown'); ?>

                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Billing Cycle Toggle -->
    <div class="flex gap-2 justify-center">
        <a href="<?php echo e(route('reseller.packages.index', ['cycle' => 'monthly'])); ?>"
           class="px-6 py-2 rounded-lg font-medium transition-colors <?php echo e($billingCycle === 'monthly' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700'); ?>">
            Monthly Plans
        </a>
        <a href="<?php echo e(route('reseller.packages.index', ['cycle' => 'annually'])); ?>"
           class="px-6 py-2 rounded-lg font-medium transition-colors <?php echo e($billingCycle === 'annually' ? 'bg-purple-600 text-white' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-700'); ?>">
            Annual Plans
        </a>
    </div>

    <!-- Package Cards Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php $__empty_1 = true; $__currentLoopData = $packages; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $package): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <div class="rounded-xl border-2 p-6 transition-all <?php echo e($user->reseller_package_id === $package->id ? 'border-purple-500 bg-purple-50 dark:bg-purple-950' : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900'); ?>">
                <!-- Header -->
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-slate-900 dark:text-white"><?php echo e($package->name); ?></h3>
                    <?php if($user->reseller_package_id === $package->id): ?>
                        <span class="px-2.5 py-1 bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 text-xs font-semibold rounded">Your Plan</span>
                    <?php elseif($user->resellerPackage && $package->price < $user->resellerPackage->price): ?>
                        <span class="px-2.5 py-1 bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 text-xs font-semibold rounded">Lower Tier</span>
                    <?php endif; ?>
                </div>

                <!-- Price -->
                <div class="mb-6 pb-6 border-b border-slate-200 dark:border-slate-700">
                    <p class="text-sm text-slate-600 dark:text-slate-400">Price</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-1">
                        Ksh <?php echo e(number_format($package->price, 0)); ?>

                        <span class="text-lg text-slate-600 dark:text-slate-400 font-normal">/<?php echo e($billingCycle === 'monthly' ? 'mo' : 'yr'); ?></span>
                    </p>
                </div>

                <!-- Features -->
                <div class="space-y-3 mb-6">
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($package->storage_space); ?> Service Slots</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">Active services you can manage</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <svg class="w-5 h-5 text-emerald-600 dark:text-emerald-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                        </svg>
                        <div>
                            <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($package->max_users); ?> Customers</p>
                            <p class="text-xs text-slate-600 dark:text-slate-400">Maximum customer accounts</p>
                        </div>
                    </div>
                    <?php if($package->description): ?>
                        <p class="text-sm text-slate-600 dark:text-slate-400 pt-2"><?php echo e($package->description); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Action Button -->
                <?php if($user->reseller_package_id === $package->id): ?>
                    <button disabled class="w-full px-4 py-2 bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300 rounded-lg text-sm font-medium cursor-default">
                        Current Plan
                    </button>
                <?php elseif($user->resellerPackage && $package->price < $user->resellerPackage->price): ?>
                    <button disabled class="w-full px-4 py-2 bg-slate-100 dark:bg-slate-700 text-slate-600 dark:text-slate-400 rounded-lg text-sm font-medium cursor-not-allowed">
                        Cannot Downgrade
                    </button>
                <?php else: ?>
                    <form action="<?php echo e(route('reseller.packages.subscribe', $package)); ?>" method="POST" onsubmit="return confirm('You will be charged Ksh <?php echo e(number_format($package->price, 0)); ?> for this plan. Continue?')">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="w-full px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-lg text-sm font-medium transition-colors">
                            <?php echo e($user->resellerPackage ? 'Upgrade' : 'Subscribe'); ?>

                        </button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <div class="col-span-full text-center py-12">
                <svg class="mx-auto w-12 h-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8-4m0 0l8 4m0 0v10l-8 4m0-10L4 7m0 10v10l8 4m8-4v-10l-8-4"/>
                </svg>
                <p class="text-slate-600 dark:text-slate-400 font-medium mt-2">No <?php echo e($billingCycle); ?> packages available</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/reseller/packages/index.blade.php ENDPATH**/ ?>