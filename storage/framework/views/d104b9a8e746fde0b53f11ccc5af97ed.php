<?php $__env->startSection('title', 'Dashboard'); ?>

<?php $__env->startSection('breadcrumb'); ?>
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Dashboard</p>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-8">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Dashboard</h1>
        <p class="text-slate-600 mt-1">Welcome back! Here's your business overview.</p>
    </div>

    <!-- Primary Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Customers -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Customers</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($totalCustomers); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.856-1.487M15 10h.01M10 10a4 4 0 11-8 0 4 4 0 018 0zM9 20H3v-2a6 6 0 0112 0v2z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Active accounts</p>
        </div>

        <!-- Active Services -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Active Services</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($activeServices); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-emerald-100 dark:bg-emerald-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Running now</p>
        </div>

        <!-- Unpaid Invoices -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Unpaid Invoices</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">$<?php echo e(number_format($unpaidInvoiceTotal, 2)); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Awaiting payment</p>
        </div>

        <!-- Total Revenue -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Total Revenue</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">$<?php echo e(number_format($totalRevenue, 2)); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-violet-100 dark:bg-violet-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">All time</p>
        </div>
    </div>

    <!-- Secondary Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Suspended Services -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Suspended Services</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($suspendedServices); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-amber-100 dark:bg-amber-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 5v1m7-13a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Need attention</p>
        </div>

        <!-- Overdue Invoices -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Overdue Invoices</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">$<?php echo e(number_format($overdueInvoiceTotal, 2)); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Payment due</p>
        </div>

        <!-- Pending Payments -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Pending Payments</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2">$<?php echo e(number_format($pendingPayments, 2)); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">In progress</p>
        </div>

        <!-- Urgent Tickets -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 hover:border-slate-300 dark:hover:border-slate-700 transition-colors">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-medium text-slate-600 dark:text-slate-400">Urgent Tickets</p>
                    <p class="text-3xl font-bold text-slate-900 dark:text-white mt-2"><?php echo e($urgentTickets); ?></p>
                </div>
                <div class="w-12 h-12 rounded-xl bg-red-100 dark:bg-red-950 flex items-center justify-center">
                    <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-slate-500 mt-4">Needs action</p>
        </div>
    </div>

    <!-- Analytics Section -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Revenue Trend Chart -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Revenue Trend</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Last 30 days</p>
            </div>
            <div class="p-6">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>

        <!-- Signup Trend Chart -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <h2 class="text-lg font-semibold text-slate-900 dark:text-white">New Signups</h2>
                <p class="text-sm text-slate-600 dark:text-slate-400 mt-1">Last 7 days</p>
            </div>
            <div class="p-6">
                <canvas id="signupChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Status Breakdowns -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Service Status -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Service Status Breakdown</h2>
            <div class="space-y-4">
                <?php
                    $statusColors = [
                        'active' => ['bg' => 'bg-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-400'],
                        'suspended' => ['bg' => 'bg-amber-500', 'text' => 'text-amber-700 dark:text-amber-400'],
                        'terminated' => ['bg' => 'bg-red-500', 'text' => 'text-red-700 dark:text-red-400'],
                        'cancelled' => ['bg' => 'bg-slate-500', 'text' => 'text-slate-700 dark:text-slate-400'],
                    ];
                    $total = array_sum($serviceStatus);
                ?>
                <?php $__currentLoopData = $serviceStatus; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php $percentage = $total > 0 ? round(($count / $total) * 100) : 0; ?>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300"><?php echo e(ucfirst($status)); ?></span>
                            <span class="text-sm font-semibold text-slate-900 dark:text-white"><?php echo e($count); ?> (<?php echo e($percentage); ?>%)</span>
                        </div>
                        <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2">
                            <div class="h-2 rounded-full <?php echo e($statusColors[$status]['bg']); ?>" style="width: <?php echo e($percentage); ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>

        <!-- Invoice Status -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
            <h2 class="text-lg font-semibold text-slate-900 dark:text-white mb-6">Invoice Status Breakdown</h2>
            <div class="space-y-4">
                <?php
                    $invoiceStatusColors = [
                        'unpaid' => ['bg' => 'bg-amber-500', 'text' => 'text-amber-700 dark:text-amber-400'],
                        'paid' => ['bg' => 'bg-emerald-500', 'text' => 'text-emerald-700 dark:text-emerald-400'],
                        'overdue' => ['bg' => 'bg-red-500', 'text' => 'text-red-700 dark:text-red-400'],
                        'cancelled' => ['bg' => 'bg-slate-500', 'text' => 'text-slate-700 dark:text-slate-400'],
                    ];
                    $invoiceTotal = array_sum($invoiceStatus);
                ?>
                <?php $__currentLoopData = $invoiceStatus; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $status => $count): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php $percentage = $invoiceTotal > 0 ? round(($count / $invoiceTotal) * 100) : 0; ?>
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <span class="text-sm font-medium text-slate-700 dark:text-slate-300"><?php echo e(ucfirst($status)); ?></span>
                            <span class="text-sm font-semibold text-slate-900 dark:text-white"><?php echo e($count); ?> (<?php echo e($percentage); ?>%)</span>
                        </div>
                        <div class="w-full bg-slate-200 dark:bg-slate-800 rounded-full h-2">
                            <div class="h-2 rounded-full <?php echo e($invoiceStatusColors[$status]['bg']); ?>" style="width: <?php echo e($percentage); ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </div>
        </div>
    </div>

    <!-- Activity Feeds -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Customers -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Customers</h2>
                    <a href="<?php echo e(route('admin.customers.index')); ?>" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                <?php $__empty_1 = true; $__currentLoopData = $recentCustomers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $customer): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center flex-shrink-0 text-white font-semibold">
                                <?php echo e(strtoupper(substr($customer->name, 0, 1))); ?>

                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate"><?php echo e($customer->name); ?></p>
                                <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($customer->email); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No customers yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Services -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Services</h2>
                    <a href="<?php echo e(route('admin.services.index')); ?>" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                <?php $__empty_1 = true; $__currentLoopData = $recentServices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $service): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate"><?php echo e($service->product?->name ?? 'Unknown'); ?></p>
                                <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($service->user?->name ?? 'Unknown'); ?></p>
                            </div>
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
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No services yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Payments -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Payments</h2>
                    <a href="<?php echo e(route('admin.payments.index')); ?>" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                <?php $__empty_1 = true; $__currentLoopData = $recentPayments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $payment): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($payment->user?->name ?? 'Unknown'); ?></p>
                                <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($payment->payment_method?->label() ?? 'Manual'); ?></p>
                            </div>
                            <p class="text-sm font-semibold text-slate-900 dark:text-white">$<?php echo e(number_format($payment->amount, 2)); ?></p>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No payments yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- More Activity Feeds -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Recent Invoices -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Recent Invoices</h2>
                    <a href="<?php echo e(route('admin.invoices.index')); ?>" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                <?php $__empty_1 = true; $__currentLoopData = $recentInvoices; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $invoice): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e($invoice->invoice_number); ?></p>
                                <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($invoice->user?->name ?? 'Unknown'); ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white">$<?php echo e(number_format($invoice->total, 2)); ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No invoices yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Open Tickets -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Open Tickets</h2>
                    <a href="<?php echo e(route('tickets.index')); ?>" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                <?php $__empty_1 = true; $__currentLoopData = $openTickets_data; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $ticket): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate"><?php echo e($ticket->subject ?? 'No subject'); ?></p>
                                <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($ticket->user?->name ?? 'Unknown'); ?></p>
                            </div>
                            <?php if (isset($component)) { $__componentOriginal8c81617a70e11bcf247c4db924ab1b62 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8c81617a70e11bcf247c4db924ab1b62 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.status-badge','data' => ['status' => $ticket->priority,'type' => 'priority']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('status-badge'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['status' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($ticket->priority),'type' => 'priority']); ?>
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
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No open tickets</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Products -->
        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
            <div class="p-6 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold text-slate-900 dark:text-white">Top Products</h2>
                    <a href="<?php echo e(route('admin.products.index')); ?>" class="text-xs font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300">View all →</a>
                </div>
            </div>
            <div class="divide-y divide-slate-200 dark:divide-slate-800">
                <?php $__empty_1 = true; $__currentLoopData = $topProducts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <div class="p-4 hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                        <div class="flex items-center justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-slate-900 dark:text-white truncate"><?php echo e($product->name); ?></p>
                                <p class="text-xs text-slate-600 dark:text-slate-400"><?php echo e($currency?->symbol ?? 'KES'); ?><?php echo e(number_format(($product->monthly_price ?? $product->yearly_price ?? 0) * ($currency?->exchange_rate ?? 1), 2)); ?></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-semibold text-slate-900 dark:text-white"><?php echo e($product->services_count); ?></p>
                                <p class="text-xs text-slate-600 dark:text-slate-400">active</p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <div class="p-8 text-center">
                        <p class="text-sm text-slate-500 dark:text-slate-400">No products yet</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startPush('scripts'); ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Revenue Chart
    const revenueCtx = document.getElementById('revenueChart');
    if (revenueCtx) {
        const revenueData = <?php echo $revenueData; ?>;
        const last30Days = [];
        for (let i = 29; i >= 0; i--) {
            const date = new Date();
            date.setDate(date.getDate() - i);
            last30Days.push(date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
        }

        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: last30Days,
                datasets: [{
                    label: 'Daily Revenue',
                    data: revenueData,
                    borderColor: '#8b5cf6',
                    backgroundColor: 'rgba(139, 92, 246, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 4,
                    pointBackgroundColor: '#8b5cf6',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 6,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#475569',
                            font: { size: 12, weight: '500' }
                        }
                    },
                    filler: {
                        propagate: true
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            },
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#334155' : '#e2e8f0',
                            drawBorder: false
                        }
                    },
                    x: {
                        ticks: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        }
                    }
                }
            }
        });
    }

    // Signup Chart
    const signupCtx = document.getElementById('signupChart');
    if (signupCtx) {
        const signupData = <?php echo $signupData; ?>;
        const last7Days = [];
        const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        for (let i = 6; i >= 0; i--) {
            const date = new Date();
            date.setDate(date.getDate() - i);
            last7Days.push(dayNames[date.getDay()] + ' ' + date.getDate());
        }

        new Chart(signupCtx, {
            type: 'bar',
            data: {
                labels: last7Days,
                datasets: [{
                    label: 'New Signups',
                    data: signupData,
                    backgroundColor: '#3b82f6',
                    borderRadius: 6,
                    borderSkipped: false,
                    hoverBackgroundColor: '#1e40af'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: undefined,
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#475569',
                            font: { size: 12, weight: '500' }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#334155' : '#e2e8f0',
                            drawBorder: false
                        }
                    },
                    x: {
                        ticks: {
                            color: window.matchMedia('(prefers-color-scheme: dark)').matches ? '#cbd5e1' : '#64748b',
                            font: { size: 11 }
                        },
                        grid: {
                            display: false,
                            drawBorder: false
                        }
                    }
                }
            }
        });
    }
</script>
<?php $__env->stopPush(); ?>

<?php echo $__env->make('layouts.admin', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/dashboard/admin.blade.php ENDPATH**/ ?>