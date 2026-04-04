<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>" x-data="{ dark: localStorage.getItem('theme') === 'dark' || (!localStorage.getItem('theme') && window.matchMedia('(prefers-color-scheme: dark)').matches), sidebarOpen: false }" :class="{ 'dark': dark }">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">

        <title><?php echo $__env->yieldContent('title', 'My Account'); ?> — <?php echo e(config('app.name', 'Talksasa Cloud')); ?></title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
        <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    </head>
    <body class="font-sans antialiased bg-white dark:bg-slate-950 text-slate-900 dark:text-white">
        <div class="min-h-screen flex">
            <!-- Sidebar -->
            <aside class="w-64 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 overflow-y-auto hidden md:flex md:flex-col fixed h-screen md:static">
                <!-- Logo -->
                <div class="h-16 flex items-center px-6 border-b border-slate-200 dark:border-slate-800">
                    <a href="<?php echo e(route('dashboard')); ?>" class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                            <span class="text-white font-bold text-sm">TC</span>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-slate-900 dark:text-white">Talksasa</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">Cloud</p>
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

                    <!-- Services -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Services</p>
                        <a href="<?php echo e(route('customer.services.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.services.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                            </svg>
                            <span class="text-sm font-medium">My Services</span>
                        </a>
                        <a href="<?php echo e(route('customer.orders.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.orders.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/>
                            </svg>
                            <span class="text-sm font-medium">Orders</span>
                        </a>
                        <a href="<?php echo e(route('customer.deploy-service')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.deploy-service') ? 'bg-emerald-100 dark:bg-emerald-900 text-emerald-700 dark:text-emerald-300' : 'bg-gradient-to-r from-emerald-50 to-teal-50 dark:from-emerald-950 dark:to-teal-950 text-emerald-700 dark:text-emerald-300 hover:from-emerald-100 hover:to-teal-100'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                            </svg>
                            <span class="text-sm font-medium">Deploy New Service</span>
                        </a>
                        <a href="<?php echo e(route('customer.domains.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.domains.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                            </svg>
                            <span class="text-sm font-medium">Domains</span>
                        </a>
                    </div>

                    <!-- Billing -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Billing</p>
                        <a href="<?php echo e(route('customer.invoices.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.invoices.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <span class="text-sm font-medium">Invoices</span>
                        </a>
                        <a href="<?php echo e(route('customer.payments.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.payments.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span class="text-sm font-medium">Payments</span>
                        </a>
                    </div>

                    <?php if(auth()->user()->isReseller()): ?>
                    <!-- Reseller -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-purple-500 dark:text-purple-400 uppercase tracking-wider">Reseller</p>
                        <a href="<?php echo e(route('reseller.packages.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('reseller.packages.*') ? 'bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m0 0l8-4m0 0l8 4m0 0v10l-8 4m0-10L4 7m0 10v10l8 4m8-4v-10l-8-4"/>
                            </svg>
                            <span class="text-sm font-medium">My Package</span>
                        </a>
                    </div>
                    <?php endif; ?>

                    <!-- Account -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Account</p>
                        <a href="<?php echo e(route('profile.edit')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('profile.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4z"/>
                            </svg>
                            <span class="text-sm font-medium">Profile</span>
                        </a>
                        <a href="#" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            <span class="text-sm font-medium">Security</span>
                        </a>
                    </div>

                    <!-- Support -->
                    <div class="space-y-2">
                        <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Help</p>
                        <a href="<?php echo e(route('customer.tickets.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.tickets.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800'); ?>">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                            </svg>
                            <span class="text-sm font-medium">Support</span>
                        </a>
                    </div>
                </nav>

                <!-- Account Status Footer -->
                <div class="p-4 border-t border-slate-200 dark:border-slate-800">
                    <div class="flex items-center gap-2 px-3 py-2">
                        <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                        <span class="text-xs font-medium text-slate-700 dark:text-slate-300">Account Active</span>
                    </div>
                </div>

                <!-- Sign Out -->
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

            <!-- Mobile Sidebar Backdrop -->
            <div x-show="sidebarOpen" class="fixed inset-0 bg-black/50 md:hidden z-40" @click="sidebarOpen = false"></div>

            <!-- Main Content -->
            <div class="flex-1 md:ml-0 flex flex-col">
                <!-- Top Navigation -->
                <header class="h-16 bg-white dark:bg-slate-900 border-b border-slate-200 dark:border-slate-800 flex items-center px-6">
                    <!-- Left: Hamburger + Title -->
                    <div class="flex items-center gap-4">
                        <button class="md:hidden p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg" @click="sidebarOpen = !sidebarOpen">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                            </svg>
                        </button>
                        <h1 class="hidden sm:block text-lg font-semibold"><?php echo $__env->yieldContent('title'); ?></h1>
                    </div>

                    <!-- Right: Actions + Dark Mode + Notifications + Profile -->
                    <div class="flex items-center gap-4 ml-auto">
                        <!-- Shopping Cart -->
                        <a href="<?php echo e(route('customer.cart.index')); ?>" class="relative p-2 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition">
                            <svg class="w-5 h-5 text-slate-600 dark:text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
                            </svg>
                            <?php
                                $cartItems = session('cart', []);
                                $cartCount = count($cartItems);
                            ?>
                            <?php if($cartCount > 0): ?>
                                <span class="absolute -top-1 -right-1 bg-red-600 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"><?php echo e($cartCount); ?></span>
                            <?php endif; ?>
                        </a>

                        <!-- Pay Invoice Quick Button (if customer has balance) -->
                        <button class="hidden lg:flex items-center gap-2 px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white rounded-lg text-sm font-medium transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            <span>Pay Invoice</span>
                        </button>

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

                        <!-- Account Dropdown -->
                        <div class="relative" x-data="{ accountOpen: false }">
                            <button @click="accountOpen = !accountOpen" class="flex items-center gap-2 p-1.5 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg transition">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-400 to-blue-600 flex items-center justify-center text-white text-xs font-semibold">
                                    <?php echo e(strtoupper(substr(auth()->user()->name, 0, 1))); ?>

                                </div>
                                <div class="hidden lg:block text-left">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e(auth()->user()->name); ?></p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">Customer</p>
                                </div>
                                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="!accountOpen">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
                                </svg>
                                <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" x-show="accountOpen">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7 7 7"/>
                                </svg>
                            </button>

                            <!-- Dropdown Menu -->
                            <div x-show="accountOpen" @click.outside="accountOpen = false" class="absolute right-0 mt-2 w-56 bg-white dark:bg-slate-900 rounded-lg shadow-lg border border-slate-200 dark:border-slate-800 overflow-hidden z-50" style="display: none">
                                <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800">
                                    <p class="text-sm font-medium text-slate-900 dark:text-white"><?php echo e(auth()->user()->name); ?></p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400"><?php echo e(auth()->user()->email); ?></p>
                                </div>
                                <a href="<?php echo e(route('profile.edit')); ?>" class="block px-4 py-2.5 text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800">
                                    <svg class="w-4 h-4 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/></svg>
                                    Profile
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

                <!-- Page Content -->
                <main class="flex-1 overflow-auto">
                    <div class="px-6 py-8 max-w-7xl mx-auto w-full">
                        <?php echo $__env->yieldContent('content'); ?>
                    </div>
                </main>
            </div>
        </div>

        <?php echo $__env->yieldPushContent('scripts'); ?>
    </body>
</html>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/layouts/customer.blade.php ENDPATH**/ ?>