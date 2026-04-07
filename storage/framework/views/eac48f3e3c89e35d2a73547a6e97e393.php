<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>" x-data="{ dark: localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches), sidebarOpen: localStorage.getItem('sidebarOpen') === 'true' }" @keydown.escape="sidebarOpen = false" :class="{ 'dark': dark }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

        <title><?php echo $__env->yieldContent('title', 'Admin'); ?> — <?php echo e(config('app.name', 'Talksasa Cloud')); ?></title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    </head>
    <body class="font-sans antialiased bg-white dark:bg-slate-950 text-slate-900 dark:text-white">
        <!-- Sidebar -->
        <aside class="w-64 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 overflow-y-auto flex flex-col fixed h-screen left-0 top-0 z-30">
                <!-- Logo -->
                <div class="h-16 flex items-center px-6 border-b border-slate-200 dark:border-slate-800">
                    <a href="<?php echo e(route('dashboard')); ?>" class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                            <span class="text-white font-bold text-sm">TC</span>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-900 dark:text-white">Talksasa</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Admin</p>
                        </div>
                    </a>
                </div>

                <!-- Navigation -->
                <nav class="flex-1 px-3 py-6 space-y-8">
                    <!-- Dashboard -->
                    <div class="space-y-1">
                        <a href="<?php echo e(route('dashboard')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('dashboard') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-3m0 0l7-4 7 4M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9m-9 4l4 2m-7-2l4-2"/>
                            </svg>
                            <span class="text-sm font-medium">Dashboard</span>
                        </a>
                    </div>

                    <!-- Customers -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Customers</p>
                        <a href="<?php echo e(route('admin.customers.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.customers.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.856-1.487M15 10h.01M10 10a4 4 0 11-8 0 4 4 0 018 0zM9 20H3v-2a6 6 0 0112 0v2z"/>
                            </svg>
                            <span class="text-sm font-medium">Customers</span>
                        </a>
                        <a href="<?php echo e(route('admin.resellers.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.resellers.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                            </svg>
                            <span class="text-sm font-medium">Resellers</span>
                        </a>
                    </div>

                    <!-- Catalog -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Catalog</p>
                        <a href="<?php echo e(route('admin.products.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.products.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                            </svg>
                            <span class="text-sm font-medium">Products</span>
                        </a>
                        <a href="<?php echo e(route('admin.domains.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.domains.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                            </svg>
                            <span class="text-sm font-medium">Domains & Pricing</span>
                        </a>
                        <a href="<?php echo e(route('admin.orders.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.orders.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                            </svg>
                            <span class="text-sm font-medium">Orders</span>
                        </a>
                        <a href="<?php echo e(route('admin.reseller-packages.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.reseller-packages.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8-4m0 0l8 4m0 0v10l-8 4m0-10L4 7m0 10v10l8 4m8-4v-10l-8-4"/>
                            </svg>
                            <span class="text-sm font-medium">Reseller Packages</span>
                        </a>
                    </div>

                    <!-- Billing -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Billing</p>
                        <a href="<?php echo e(route('admin.invoices.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.invoices.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <span class="text-sm font-medium">Invoices</span>
                        </a>
                        <a href="<?php echo e(route('admin.payments.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.payments.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span class="text-sm font-medium">Payments</span>
                        </a>
                    </div>

                    <!-- Infrastructure -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Infrastructure</p>
                        <a href="<?php echo e(route('admin.services.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.services.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                            </svg>
                            <span class="text-sm font-medium">Services</span>
                        </a>
                        <a href="<?php echo e(route('admin.nodes.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.nodes.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                            </svg>
                            <span class="text-sm font-medium">Nodes</span>
                        </a>
                        <a href="<?php echo e(route('admin.container-templates.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.container-templates.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/>
                            </svg>
                            <span class="text-sm font-medium">Container Templates</span>
                        </a>
                        <a href="<?php echo e(route('admin.cron.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.cron.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span class="text-sm font-medium">Cron Jobs</span>
                        </a>
                    </div>

                    <!-- Communications -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Communications</p>
                        <a href="<?php echo e(route('admin.emails.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.emails.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            <span class="text-sm font-medium">Emails</span>
                        </a>
                        <a href="<?php echo e(route('admin.sms.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.sms.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                            </svg>
                            <span class="text-sm font-medium">SMS Notifications</span>
                        </a>
                    </div>

                    <!-- Support -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Support</p>
                        <a href="<?php echo e(route('tickets.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('tickets.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                            <span class="text-sm font-medium">Tickets</span>
                        </a>
                    </div>

                    <!-- System -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">System</p>
                        <a href="<?php echo e(route('admin.settings.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.settings.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            <span class="text-sm font-medium">Platform Settings</span>
                        </a>
                    </div>
                </nav>

                <!-- Footer -->
                <div class="p-3 border-t border-slate-200 dark:border-slate-800">
                    <form method="POST" action="<?php echo e(route('logout')); ?>">
                        <?php echo csrf_field(); ?>
                        <button type="submit" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            <span class="text-sm font-medium">Sign Out</span>
                        </button>
                    </form>
                </div>
            </aside>

        <!-- Mobile Sidebar Overlay - Hidden, desktop sidebar always visible -->
        <div x-cloak x-show="false" class="fixed inset-0 bg-black/50 hidden z-20" @click="sidebarOpen = false; localStorage.setItem('sidebarOpen', 'false')"></div>

        <!-- Mobile Sidebar (Overlay) - Hidden, desktop sidebar always visible -->
        <aside x-cloak x-show="false" class="w-64 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 overflow-y-auto hidden fixed h-screen left-0 top-0 z-40"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="-translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="-translate-x-full">
            <!-- Logo -->
            <div class="h-16 flex items-center px-6 border-b border-slate-200 dark:border-slate-800">
                <a href="<?php echo e(route('dashboard')); ?>" class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                        <span class="text-white font-bold text-sm">TC</span>
                    </div>
                    <div>
                        <p class="text-sm font-bold text-slate-900 dark:text-white">Talksasa</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Admin</p>
                    </div>
                </a>
            </div>

            <!-- Navigation -->
            <nav class="flex-1 px-3 py-6 space-y-8" @click="sidebarOpen = false; localStorage.setItem('sidebarOpen', 'false')">
                <!-- Dashboard -->
                <div class="space-y-1">
                    <a href="<?php echo e(route('dashboard')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('dashboard') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-3m0 0l7-4 7 4M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9m-9 4l4 2m-7-2l4-2"/>
                        </svg>
                        <span class="text-sm font-medium">Dashboard</span>
                    </a>
                </div>

                <!-- Customers -->
                <div class="space-y-2">
                    <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Customers</p>
                    <a href="<?php echo e(route('admin.customers.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.customers.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.856-1.487M15 10h.01M10 10a4 4 0 11-8 0 4 4 0 018 0zM9 20H3v-2a6 6 0 0112 0v2z"/>
                        </svg>
                        <span class="text-sm font-medium">Customers</span>
                    </a>
                    <a href="<?php echo e(route('admin.resellers.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                        </svg>
                        <span class="text-sm font-medium">Resellers</span>
                    </a>
                </div>

                <!-- Catalog -->
                <div class="space-y-2">
                    <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Catalog</p>
                    <a href="<?php echo e(route('admin.products.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.products.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <span class="text-sm font-medium">Products</span>
                    </a>
                    <a href="<?php echo e(route('admin.domains.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.domains.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                        </svg>
                        <span class="text-sm font-medium">Domains & Pricing</span>
                    </a>
                    <a href="<?php echo e(route('admin.orders.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.orders.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                        </svg>
                        <span class="text-sm font-medium">Orders</span>
                    </a>
                    <a href="<?php echo e(route('admin.reseller-packages.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.reseller-packages.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8-4m0 0l8 4m0 0v10l-8 4m0-10L4 7m0 10v10l8 4m8-4v-10l-8-4"/>
                        </svg>
                        <span class="text-sm font-medium">Reseller Packages</span>
                    </a>
                </div>

                <!-- Billing -->
                <div class="space-y-2">
                    <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Billing</p>
                    <a href="<?php echo e(route('admin.invoices.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.invoices.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span class="text-sm font-medium">Invoices</span>
                    </a>
                    <a href="<?php echo e(route('admin.payments.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.payments.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span class="text-sm font-medium">Payments</span>
                    </a>
                </div>

                <!-- Infrastructure -->
                <div class="space-y-2">
                    <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Infrastructure</p>
                    <a href="<?php echo e(route('admin.services.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.services.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                        </svg>
                        <span class="text-sm font-medium">Services</span>
                    </a>
                    <a href="<?php echo e(route('admin.nodes.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.nodes.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                        </svg>
                        <span class="text-sm font-medium">Nodes</span>
                    </a>
                </div>

                <!-- Support -->
                <div class="space-y-2">
                    <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Support</p>
                    <a href="<?php echo e(route('tickets.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('tickets.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        <span class="text-sm font-medium">Tickets</span>
                    </a>
                </div>

                <!-- System -->
                <div class="space-y-2">
                    <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">System</p>
                    <a href="<?php echo e(route('admin.settings.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.settings.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span class="text-sm font-medium">Settings</span>
                    </a>
                </div>
            </nav>

            <!-- Footer -->
            <div class="p-3 border-t border-slate-200 dark:border-slate-800">
                <form method="POST" action="<?php echo e(route('logout')); ?>">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                        <span class="text-sm font-medium">Sign Out</span>
                    </button>
                </form>
            </div>
        </aside>

        <!-- Main Content Wrapper -->
        <div class="flex-1 ml-64 flex flex-col min-h-screen">
            <!-- Top Navigation (Sticky) -->
            <header class="sticky top-0 h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 flex items-center px-6 z-20">
            <!-- Left: Hamburger + Breadcrumb -->
            <div class="flex items-center gap-4">
                <button class="hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg" @click="sidebarOpen = !sidebarOpen; localStorage.setItem('sidebarOpen', sidebarOpen)">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>
                <?php echo $__env->yieldContent('breadcrumb'); ?>
            </div>

            <!-- Center: Search (placeholder) -->
            <div class="hidden xl:block flex-1 max-w-md mx-4">
                        <div class="w-full relative">
                            <input type="text" placeholder="Search customers, invoices..." class="w-full pl-4 pr-10 py-2 bg-slate-100 dark:bg-slate-800 border-0 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-sm placeholder-slate-500 dark:placeholder-slate-400">
                            <svg class="w-5 h-5 absolute right-3 top-1/2 transform -translate-y-1/2 text-slate-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                            </svg>
                        </div>
            </div>

            <!-- Right: Dark Mode + Notifications + Profile -->
            <div class="flex items-center gap-4 ml-auto">
                <!-- Dark Mode Toggle Switch -->
                <div class="flex items-center gap-2 px-2 py-1.5 bg-slate-100 dark:bg-slate-800 rounded-lg">
                    <svg class="w-4 h-4 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1m-16 0H1m15.364 1.636l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    <button @click="dark = !dark; localStorage.setItem('theme', dark ? 'dark' : 'light')" class="relative w-10 h-6 bg-slate-300 dark:bg-slate-600 rounded-full transition-colors focus:outline-none">
                        <span :class="dark ? 'translate-x-5' : 'translate-x-0'" class="absolute top-0.5 left-0.5 w-5 h-5 bg-white dark:bg-slate-900 rounded-full transition-transform shadow"></span>
                    </button>
                    <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 015.646 5.646 9.001 9.001 0 0120.354 15.354z"/>
                    </svg>
                </div>

                <!-- Notifications Bell (placeholder) -->
                <button class="p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition relative" x-data="{ notificationsOpen: false }">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            <span class="absolute top-0 right-0 w-2 h-2 bg-red-500 rounded-full"></span>
                        </button>

                <!-- Profile Dropdown -->
                <div class="relative" x-data="{ profileOpen: false }">
                    <button @click="profileOpen = !profileOpen" class="flex items-center gap-2 p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition">
                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xs font-semibold">
                            <?php echo e(strtoupper(substr(auth()->user()->name, 0, 1))); ?>

                        </div>
                        <div class="hidden xl:block text-left">
                                        <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e(auth()->user()->name); ?></p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">Administrator</p>
                    </div>
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="!profileOpen">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                    </svg>
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="profileOpen">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7 7 7"/>
                    </svg>
                    </button>

                    <!-- Dropdown Menu -->
                    <div x-show="profileOpen" @click.outside="profileOpen = false" class="absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-lg shadow-lg border border-slate-200 dark:border-slate-800 overflow-hidden z-50" style="display: none">
                        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800">
                            <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e(auth()->user()->name); ?></p>
                            <p class="text-xs text-slate-500 dark:text-slate-400"><?php echo e(auth()->user()->email); ?></p>
                        </div>
                        <a href="<?php echo e(route('profile.edit')); ?>" class="block px-4 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">
                            <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
                            Profile & Settings
                        </a>
                        <form method="POST" action="<?php echo e(route('logout')); ?>" class="border-t border-slate-200 dark:border-slate-800">
                            <?php echo csrf_field(); ?>
                            <button type="submit" class="w-full text-left px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-slate-100 dark:hover:bg-slate-800">
                                <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                                Sign Out
                            </button>
                        </form>
                    </div>
                </div>
            </header>

            <?php if(session('impersonating')): ?>
            <!-- Impersonation Banner -->
            <div class="h-12 bg-amber-50 dark:bg-amber-950 border-b border-amber-200 dark:border-amber-800 flex items-center px-6 justify-between">
                <div class="flex items-center gap-2">
                    <svg class="w-5 h-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12a9 9 0 11-18 0 9 9 0 0118 0m-.5 4.5h.01"/>
                    </svg>
                    <span class="text-sm font-medium text-amber-900 dark:text-amber-100">You are viewing as a customer. <strong><?php echo e(auth()->user()->name); ?></strong></span>
                </div>
                <form method="POST" action="<?php echo e(route('admin.exit-impersonation')); ?>" class="flex items-center gap-2">
                    <?php echo csrf_field(); ?>
                    <button type="submit" class="px-4 py-1.5 bg-amber-600 hover:bg-amber-700 dark:bg-amber-700 dark:hover:bg-amber-600 text-white text-sm font-medium rounded-lg transition">
                        Exit View
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Flash Messages -->
            <?php if(session('success')): ?>
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 5000)"
                x-transition:leave="transition ease-in duration-300"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2"
                class="mx-6 mt-4 flex items-center gap-3 px-4 py-3 bg-emerald-50 dark:bg-emerald-950 border border-emerald-200 dark:border-emerald-800 text-emerald-800 dark:text-emerald-200 rounded-lg">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm font-medium flex-1"><?php echo e(session('success')); ?></p>
                <button @click="show = false" class="text-emerald-600 dark:text-emerald-400 hover:text-emerald-800 dark:hover:text-emerald-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <?php endif; ?>
            <?php if(session('error')): ?>
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 7000)"
                x-transition:leave="transition ease-in duration-300"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 -translate-y-2"
                class="mx-6 mt-4 flex items-center gap-3 px-4 py-3 bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-200 rounded-lg">
                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4v2m0 4v2m0-14a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm font-medium flex-1"><?php echo e(session('error')); ?></p>
                <button @click="show = false" class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <?php endif; ?>

            <!-- Page Content -->
            <main class="flex-1 overflow-auto bg-slate-50 dark:bg-slate-950">
                <div class="px-6 py-8 max-w-7xl mx-auto w-full">
                    <?php echo $__env->yieldContent('content'); ?>
                </div>
            </main>
            </div>
        </div>

        <?php echo $__env->yieldPushContent('scripts'); ?>
    </body>
</html>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/layouts/admin.blade.php ENDPATH**/ ?>