<aside class="w-64 bg-white dark:bg-slate-900 border-r border-slate-200 dark:border-slate-800 overflow-y-auto hidden md:flex flex-col">
    <!-- Logo -->
    <div class="h-16 flex items-center px-6 border-b border-slate-200 dark:border-slate-800">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-xl bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center">
                <span class="text-white font-bold text-sm">TC</span>
            </div>
            <div>
                <p class="text-sm font-bold text-slate-900 dark:text-white">Talksasa</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Cloud</p>
            </div>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="flex-1 px-3 py-6 space-y-1">
        <a href="<?php echo e(route('dashboard')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('dashboard') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'); ?>">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-3m0 0l7-4 7 4M5 9v10a1 1 0 001 1h12a1 1 0 001-1V9m-9 4l4 2m-7-2l4-2"/>
            </svg>
            <span class="text-sm font-medium">Dashboard</span>
        </a>

        <?php if(auth()->guard()->check()): ?>
            <?php if(auth()->user()->is_admin): ?>
                <div class="pt-4">
                    <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Admin</p>
                    <a href="<?php echo e(route('admin.products.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('admin.products.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'); ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        <span class="text-sm font-medium">Products</span>
                    </a>
                </div>
            <?php endif; ?>

            <div class="pt-4">
                <p class="px-4 py-2 text-xs font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wider">Management</p>
                <a href="<?php echo e(route('customer.services.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.services.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                    </svg>
                    <span class="text-sm font-medium">Services</span>
                </a>

                <a href="<?php echo e(route('customer.invoices.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.invoices.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span class="text-sm font-medium">Invoices</span>
                </a>

                <a href="<?php echo e(route('customer.tickets.index')); ?>" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition-all <?php echo e(request()->routeIs('customer.tickets.*') ? 'bg-blue-100 dark:bg-blue-900 text-blue-700 dark:text-blue-300' : 'text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white'); ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                    <span class="text-sm font-medium">Support</span>
                </a>
            </div>
        <?php endif; ?>
    </nav>

    <!-- Footer -->
    <div class="p-3 border-t border-slate-200 dark:border-slate-800">
        <form method="POST" action="<?php echo e(route('logout')); ?>">
            <?php echo csrf_field(); ?>
            <button type="submit" class="w-full flex items-center gap-3 px-4 py-2.5 rounded-lg text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-800 transition-all">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                </svg>
                <span class="text-sm font-medium">Logout</span>
            </button>
        </form>
    </div>
</aside>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/layouts/sidebar.blade.php ENDPATH**/ ?>