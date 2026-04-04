<?php $__env->startSection('title', 'My Dashboard'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <!-- Welcome Banner -->
    <div class="bg-gradient-to-r from-blue-600 to-blue-700 dark:from-blue-900 dark:to-blue-800 rounded-xl border border-blue-500 dark:border-blue-700 p-8 text-white">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-3xl font-bold">Welcome back, <?php echo e(auth()->user()->name); ?></h1>
                <p class="text-blue-100 mt-2">Manage your services, invoices, and payments in one place.</p>
            </div>
            <div class="flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-100 dark:bg-emerald-950">
                <div class="w-2 h-2 rounded-full bg-emerald-600"></div>
                <span class="text-sm font-medium text-emerald-700 dark:text-emerald-300">Account Active</span>
            </div>
        </div>
    </div>

    <!-- Quick Stats -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Active Services -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Services</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($activeServices->count()); ?></p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Running now</p>
        </div>

        <!-- Unpaid Invoices Count -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Unpaid Invoices</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($upcomingDueInvoices->count()); ?></p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-amber-100 dark:bg-amber-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Awaiting payment</p>
        </div>

        <!-- Outstanding Balance -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Outstanding Balance</p>
                    <p class="text-3xl font-bold <?php echo e($outstandingBalance > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'); ?> mt-2">$<?php echo e(number_format($outstandingBalance, 2)); ?></p>
                </div>
                <div class="w-12 h-12 rounded-lg <?php echo e($outstandingBalance > 0 ? 'bg-amber-100 dark:bg-amber-950' : 'bg-emerald-100 dark:bg-emerald-950'); ?> flex items-center justify-center">
                    <svg class="w-6 h-6 <?php echo e($outstandingBalance > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'); ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4"><?php echo e($outstandingBalance > 0 ? 'Due soon' : 'All paid'); ?></p>
        </div>

        <!-- Open Tickets -->
        <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Open Support Tickets</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($openTickets->count()); ?></p>
                </div>
                <div class="w-12 h-12 rounded-lg bg-red-100 dark:bg-red-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Need help</p>
        </div>
    </div>

    <!-- Deploy New Service CTA Banner -->
    <div class="bg-gradient-to-r from-emerald-600 to-teal-600 dark:from-emerald-700 dark:to-teal-700 rounded-xl p-8 text-white">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-2xl font-bold">Ready to expand?</h2>
                <p class="text-emerald-100 mt-1">Deploy a new service instantly and scale your infrastructure</p>
            </div>
            <button class="px-6 py-3 bg-white hover:bg-emerald-50 text-emerald-600 font-semibold rounded-lg transition flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Deploy Service
            </button>
        </div>
    </div>

    <!-- Content Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Invoices & Payments (left column spans 2) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Recent Invoices -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Invoices</h2>
                    <a href="<?php echo e(route('customer.invoices.index')); ?>" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
                <?php if($upcomingDueInvoices->count() > 0): ?>
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php $__currentLoopData = $upcomingDueInvoices->take(5); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $invoice): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white"><?php echo e($invoice->invoice_number); ?></p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Due: <?php echo e($invoice->due_date->format('M d, Y')); ?></p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900 dark:text-white">$<?php echo e(number_format($invoice->total, 2)); ?></p>
                                    <?php if (isset($component)) { $__componentOriginal8c81617a70e11bcf247c4db924ab1b62 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8c81617a70e11bcf247c4db924ab1b62 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.status-badge','data' => ['status' => $invoice->status,'type' => 'invoice']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('status-badge'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['status' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($invoice->status),'type' => 'invoice']); ?>
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
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                <?php else: ?>
                    <div class="p-12 text-center">
                        <p class="text-slate-500 dark:text-slate-400">All invoices paid!</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Recent Payments -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Payments</h2>
                    <a href="<?php echo e(route('customer.payments.index')); ?>" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">View all →</a>
                </div>
                <div class="divide-y divide-slate-200 dark:divide-slate-800">
                    <?php $__empty_1 = true; $__currentLoopData = $activeServices->take(3); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                        <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <div class="flex items-center justify-between">
                                <div class="flex-1">
                                    <p class="font-medium text-slate-900 dark:text-white"><?php echo e($service->product?->name ?? 'Service'); ?></p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">Renewal: <?php echo e($service->next_due_date?->format('M d, Y') ?? 'N/A'); ?></p>
                                </div>
                                <p class="font-semibold text-slate-900 dark:text-white">$<?php echo e(number_format($service->product?->price ?? 0, 2)); ?></p>
                            </div>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                        <div class="p-12 text-center">
                            <p class="text-slate-500 dark:text-slate-400">No services yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Right Sidebar: Services & Support -->
        <div class="space-y-6">
            <!-- Active Services Quick List -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 overflow-hidden">
                <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                    <h3 class="font-semibold text-slate-900 dark:text-white">My Services</h3>
                </div>
                <?php if($activeServices->count() > 0): ?>
                    <div class="divide-y divide-slate-200 dark:divide-slate-800">
                        <?php $__currentLoopData = $activeServices->take(5); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                                <p class="font-medium text-sm text-slate-900 dark:text-white"><?php echo e($service->product?->name ?? 'Service'); ?></p>
                                <div class="flex items-center gap-2 mt-2">
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
                                </div>
                            </div>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                <?php else: ?>
                    <div class="p-6 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No active services</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Account Status Card -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Account Status</h3>
                <div class="space-y-3 text-sm">
                    <div>
                        <p class="text-slate-600 dark:text-slate-400">Email</p>
                        <p class="font-medium text-slate-900 dark:text-white text-xs mt-1"><?php echo e(auth()->user()->email); ?></p>
                    </div>
                    <div>
                        <p class="text-slate-600 dark:text-slate-400">Status</p>
                        <div class="flex items-center gap-2 mt-1">
                            <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                            <span class="font-medium text-emerald-600 dark:text-emerald-400">Active</span>
                        </div>
                    </div>
                    <div>
                        <p class="text-slate-600 dark:text-slate-400">Member Since</p>
                        <p class="font-medium text-slate-900 dark:text-white text-xs mt-1"><?php echo e(auth()->user()->created_at->format('M d, Y')); ?></p>
                    </div>
                </div>
            </div>

            <!-- Support Card -->
            <div class="bg-gradient-to-br from-blue-600 to-blue-700 dark:from-blue-900 dark:to-blue-800 rounded-xl border border-blue-500 dark:border-blue-700 p-6 text-white">
                <h3 class="font-semibold mb-2">Need Help?</h3>
                <p class="text-sm text-blue-100 mb-4">Get support from our team</p>
                <a href="<?php echo e(route('customer.tickets.index')); ?>" class="inline-flex items-center gap-2 px-4 py-2 bg-white hover:bg-blue-50 text-blue-600 font-medium rounded-lg text-sm transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    Open Ticket
                </a>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/dashboard/customer.blade.php ENDPATH**/ ?>