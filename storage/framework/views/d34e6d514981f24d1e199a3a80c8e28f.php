<?php $__env->startSection('title', 'Reseller: ' . $user->name); ?>

<?php $__env->startSection('breadcrumb'); ?>
<div class="flex items-center gap-2 text-sm">
    <a href="<?php echo e(route('admin.resellers.index')); ?>" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Resellers</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium"><?php echo e($user->name); ?></p>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <div class="flex items-start justify-between">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-purple-400 to-purple-600 flex items-center justify-center text-white font-bold text-2xl">
                    <?php echo e(substr($user->name, 0, 1)); ?>

                </div>
                <div>
                    <h1 class="text-3xl font-bold text-slate-900 dark:text-white"><?php echo e($user->name); ?></h1>
                    <p class="text-slate-600 dark:text-slate-400 mt-1"><?php echo e($user->email); ?></p>
                    <?php if($user->company_name): ?>
                        <p class="text-slate-600 dark:text-slate-400 mt-1 font-medium"><?php echo e($user->company_name); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Status & Actions -->
            <div class="text-right space-y-2">
                <div>
                    <span class="inline-block px-3 py-1 bg-green-100 dark:bg-green-950 text-green-700 dark:text-green-300 rounded-full text-sm font-medium">
                        Active Reseller
                    </span>
                </div>
                <form action="<?php echo e(route('admin.resellers.demote', $user)); ?>" method="POST" class="inline">
                    <?php echo csrf_field(); ?>
                    <?php if (isset($component)) { $__componentOriginal603c875b7c312212746d277aee5ca6d2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal603c875b7c312212746d277aee5ca6d2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.confirmation-dialog','data' => ['title' => 'Demote Reseller?','message' => 'This user will no longer have reseller privileges.','confirmText' => 'Demote','danger' => true,'action' => route('admin.resellers.demote', $user)]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('confirmation-dialog'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['title' => 'Demote Reseller?','message' => 'This user will no longer have reseller privileges.','confirmText' => 'Demote','danger' => true,'action' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(route('admin.resellers.demote', $user))]); ?>
                        <button type="button" class="px-4 py-2 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30 rounded-lg transition text-sm font-medium">
                            Remove Reseller Status
                        </button>
                     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal603c875b7c312212746d277aee5ca6d2)): ?>
<?php $attributes = $__attributesOriginal603c875b7c312212746d277aee5ca6d2; ?>
<?php unset($__attributesOriginal603c875b7c312212746d277aee5ca6d2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal603c875b7c312212746d277aee5ca6d2)): ?>
<?php $component = $__componentOriginal603c875b7c312212746d277aee5ca6d2; ?>
<?php unset($__componentOriginal603c875b7c312212746d277aee5ca6d2); ?>
<?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Services Managed</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($services->count()); ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Customers Served</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($customerIds->count()); ?></p>
        </div>
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <p class="text-sm font-medium text-slate-600 dark:text-slate-400 uppercase">Member Since</p>
            <p class="text-lg font-bold text-slate-900 dark:text-white mt-2"><?php echo e($user->created_at->format('M d, Y')); ?></p>
        </div>
    </div>

    <!-- Tabbed Content -->
    <div x-data="{ activeTab: 'overview' }" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800">
        <!-- Tab Navigation -->
        <div class="border-b border-slate-200 dark:border-slate-800">
            <div class="flex gap-1 px-6">
                <button @click="activeTab = 'overview'" :class="activeTab === 'overview' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Overview
                </button>
                <button @click="activeTab = 'services'" :class="activeTab === 'services' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Services (<?php echo e($services->count()); ?>)
                </button>
                <button @click="activeTab = 'customers'" :class="activeTab === 'customers' ? 'border-b-2 border-blue-600 text-blue-600' : 'text-slate-600 dark:text-slate-400'" class="px-4 py-4 font-medium transition-colors">
                    Customers (<?php echo e($customerIds->count()); ?>)
                </button>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="p-6">
            <!-- Overview Tab -->
            <div x-show="activeTab === 'overview'" class="space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Contact Information -->
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Contact Information</h3>
                        <div class="space-y-3 text-sm">
                            <div>
                                <p class="text-slate-600 dark:text-slate-400 mb-1">Email</p>
                                <p class="text-slate-900 dark:text-white font-medium"><?php echo e($user->email); ?></p>
                            </div>
                            <?php if($user->phone): ?>
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400 mb-1">Phone</p>
                                    <p class="text-slate-900 dark:text-white font-medium"><?php echo e($user->phone); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Reseller Information -->
                    <div>
                        <h3 class="text-lg font-semibold text-slate-900 dark:text-white mb-4">Reseller Information</h3>
                        <div class="space-y-3 text-sm">
                            <div>
                                <p class="text-slate-600 dark:text-slate-400 mb-1">Status</p>
                                <p class="text-slate-900 dark:text-white font-medium">Active</p>
                            </div>
                            <?php if($user->company_name): ?>
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400 mb-1">Company</p>
                                    <p class="text-slate-900 dark:text-white font-medium"><?php echo e($user->company_name); ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Placeholder Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6 border-t border-slate-200 dark:border-slate-800">
                    <div class="p-6 bg-gradient-to-br from-amber-50 to-amber-50/50 dark:from-amber-950/20 dark:to-amber-950/10 border border-amber-200 dark:border-amber-900/30 rounded-lg">
                        <h4 class="font-semibold text-amber-900 dark:text-amber-200 mb-2">Pricing Tiers</h4>
                        <p class="text-sm text-amber-800 dark:text-amber-300">Configure custom pricing for this reseller (coming soon)</p>
                    </div>
                    <div class="p-6 bg-gradient-to-br from-purple-50 to-purple-50/50 dark:from-purple-950/20 dark:to-purple-950/10 border border-purple-200 dark:border-purple-900/30 rounded-lg">
                        <h4 class="font-semibold text-purple-900 dark:text-purple-200 mb-2">Commission & Wallet</h4>
                        <p class="text-sm text-purple-800 dark:text-purple-300">View earned commissions and wallet balance (coming soon)</p>
                    </div>
                </div>
            </div>

            <!-- Services Tab -->
            <div x-show="activeTab === 'services'">
                <?php if($services->count() > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800">
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Service</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Customer</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Product</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Status</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Next Renewal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php $__currentLoopData = $services; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="py-3 px-4">
                                            <a href="<?php echo e(route('admin.services.show', $service)); ?>" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                                <?php echo e($service->name); ?>

                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            <?php echo e($service->user->name); ?>

                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            <?php echo e($service->product->name); ?>

                                        </td>
                                        <td class="py-3 px-4">
                                            <?php if (isset($component)) { $__componentOriginal8c81617a70e11bcf247c4db924ab1b62 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8c81617a70e11bcf247c4db924ab1b62 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.status-badge','data' => ['status' => $service->status,'type' => 'service']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('status-badge'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['status' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($service->status),'type' => 'service']); ?>
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
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            <?php if($service->next_due_date): ?>
                                                <?php echo e($service->next_due_date->format('M d, Y')); ?>

                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <p class="text-slate-600 dark:text-slate-400">No services managed yet</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Customers Tab -->
            <div x-show="activeTab === 'customers'">
                <?php if($customerIds->count() > 0): ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 dark:border-slate-800">
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Customer</th>
                                    <th class="text-left py-3 px-4 font-semibold text-slate-900 dark:text-white">Email</th>
                                    <th class="text-center py-3 px-4 font-semibold text-slate-900 dark:text-white">Services</th>
                                    <th class="text-right py-3 px-4 font-semibold text-slate-900 dark:text-white">Total Spend</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                <?php $__currentLoopData = $customers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $customer): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50 transition-colors">
                                        <td class="py-3 px-4">
                                            <a href="<?php echo e(route('admin.customers.show', $customer)); ?>" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                                <?php echo e($customer->name); ?>

                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-slate-600 dark:text-slate-400">
                                            <?php echo e($customer->email); ?>

                                        </td>
                                        <td class="py-3 px-4 text-center">
                                            <span class="inline-block px-3 py-1 bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300 rounded-full text-xs font-medium">
                                                <?php echo e($customer->services_count ?? 0); ?>

                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-right font-medium text-slate-900 dark:text-white">
                                            <?php if (isset($component)) { $__componentOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal0b3ecfeb70903ca113e6e8b4d451eebf = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.currency-formatter','data' => ['amount' => $customer->total_spent ?? 0,'currency' => 'KES']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('currency-formatter'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['amount' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($customer->total_spent ?? 0),'currency' => 'KES']); ?>
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
                                    </tr>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center py-12">
                        <p class="text-slate-600 dark:text-slate-400">No customers served yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/admin/resellers/show.blade.php ENDPATH**/ ?>