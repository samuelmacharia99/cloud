<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Domain Search Results — <?php echo e(config('app.name', 'Talksasa Cloud')); ?></title>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
</head>
<body class="antialiased bg-white">
    <!-- Navigation -->
    <nav class="fixed w-full top-0 z-50 bg-white/95 backdrop-blur-md border-b border-gray-100">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <a href="/" class="flex items-center gap-2 hover:opacity-75 transition">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-blue-700 flex items-center justify-center">
                        <span class="text-white font-bold">TC</span>
                    </div>
                    <span class="text-xl font-bold text-gray-900">Talksasa</span>
                </a>
            </div>

            <div class="flex items-center gap-4">
                <a href="<?php echo e(route('login')); ?>" class="hidden sm:inline text-gray-700 hover:text-blue-600 transition font-medium">Login</a>
                <a href="<?php echo e(route('register')); ?>" class="px-6 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition">Get Started</a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <section class="pt-32 pb-20 px-4 sm:px-6 lg:px-8 bg-gradient-to-br from-slate-50 via-blue-50 to-slate-50">
        <div class="max-w-4xl mx-auto">
            <!-- Search Header -->
            <div class="mb-12 text-center">
                <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">Domain Search Results</h1>
                <p class="text-xl text-gray-600 mb-8">Showing results for <span class="font-semibold text-blue-600">"<?php echo e($searchQuery); ?>"</span></p>

                <!-- New Search Form -->
                <form action="<?php echo e(route('domains.search.public')); ?>" method="GET" class="flex gap-2 max-w-2xl mx-auto mb-8">
                    <div class="flex-1 relative">
                        <input
                            type="text"
                            name="q"
                            value="<?php echo e($searchQuery); ?>"
                            placeholder="Try another domain..."
                            class="w-full px-6 py-4 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-blue-600 transition text-lg"
                        >
                    </div>
                    <button type="submit" class="px-8 py-4 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition">
                        Search
                    </button>
                </form>
            </div>

            <?php if(empty($results)): ?>
                <!-- No Results State -->
                <div class="bg-white rounded-xl border-2 border-dashed border-gray-300 p-12 text-center">
                    <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.658 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">No domains to display</h3>
                    <p class="text-gray-600 mb-6">Try searching for a domain name to see availability and pricing.</p>
                    <a href="/" class="inline-block px-6 py-2.5 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition">
                        Back to Home
                    </a>
                </div>
            <?php else: ?>
                <!-- Results Grid -->
                <div class="space-y-4">
                    <?php
                        $availableDomains = array_filter($results, fn($r) => $r['available']);
                        $unavailableDomains = array_filter($results, fn($r) => !$r['available']);
                    ?>

                    <?php if(!empty($availableDomains)): ?>
                        <div class="mb-8">
                            <h2 class="text-2xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-green-100 text-green-600">
                                    ✓
                                </span>
                                Available Domains (<?php echo e(count($availableDomains)); ?>)
                            </h2>

                            <div class="space-y-3">
                                <?php $__currentLoopData = $availableDomains; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $result): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <div class="bg-white rounded-xl border border-green-200 p-6 hover:shadow-lg transition">
                                        <div class="flex items-center justify-between">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <h3 class="text-2xl font-bold text-gray-900 font-mono">
                                                        <?php echo e($result['full_domain']); ?>

                                                    </h3>
                                                    <span class="inline-block px-3 py-1 bg-green-100 text-green-700 rounded-full text-sm font-semibold">
                                                        Available
                                                    </span>
                                                </div>
                                                <p class="text-gray-600">1 year registration</p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-3xl font-bold text-gray-900">
                                                    KES <?php echo e(number_format($result['price'], 0)); ?>

                                                </p>
                                                <p class="text-sm text-gray-500">per year</p>
                                            </div>
                                        </div>

                                        <?php if(auth()->guard()->check()): ?>
                                            <div class="mt-4 pt-4 border-t border-gray-200 flex gap-3">
                                                <form action="<?php echo e(route('customer.cart.add')); ?>" method="POST" style="display:inline;" class="flex-1">
                                                    <?php echo csrf_field(); ?>
                                                    <input type="hidden" name="item_type" value="domain">
                                                    <input type="hidden" name="item_name" value="<?php echo e($result['full_domain']); ?>">
                                                    <input type="hidden" name="item_price" value="<?php echo e($result['price']); ?>">
                                                    <button type="submit" class="w-full px-4 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition">
                                                        Add to Cart
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <div class="mt-4 pt-4 border-t border-gray-200">
                                                <a href="<?php echo e(route('register')); ?>?domain=<?php echo e($result['full_domain']); ?>" class="inline-block w-full text-center px-4 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-lg font-semibold hover:shadow-lg transition">
                                                    Register Now
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if(!empty($unavailableDomains)): ?>
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900 mb-4 flex items-center gap-2">
                                <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-gray-200 text-gray-600">
                                    ✗
                                </span>
                                Unavailable Domains (<?php echo e(count($unavailableDomains)); ?>)
                            </h2>

                            <div class="space-y-3">
                                <?php $__currentLoopData = $unavailableDomains; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $result): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                    <div class="bg-white rounded-xl border border-gray-200 p-6 opacity-60">
                                        <div class="flex items-center justify-between">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-3 mb-2">
                                                    <h3 class="text-2xl font-bold text-gray-900 font-mono">
                                                        <?php echo e($result['full_domain']); ?>

                                                    </h3>
                                                    <span class="inline-block px-3 py-1 bg-gray-100 text-gray-700 rounded-full text-sm font-semibold">
                                                        Unavailable
                                                    </span>
                                                </div>
                                                <p class="text-gray-600">This domain is already registered</p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Alternative Suggestions -->
                <div class="mt-12 bg-blue-50 rounded-xl border border-blue-200 p-8">
                    <h3 class="text-lg font-bold text-gray-900 mb-4">Couldn't find what you're looking for?</h3>
                    <p class="text-gray-700 mb-6">Try these alternatives:</p>
                    <div class="space-y-2 text-gray-700">
                        <p>• Try different domain extensions (.co.ke, .net, .org)</p>
                        <p>• Add numbers or words to make it unique</p>
                        <p>• Contact us at support@talksasa.cloud for assistance</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 text-gray-400 py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center">
                <p>&copy; 2026 Talksasa Cloud. All rights reserved.</p>
                <div class="flex gap-4">
                    <a href="/" class="hover:text-white transition">Home</a>
                    <a href="#" class="hover:text-white transition">Privacy</a>
                    <a href="#" class="hover:text-white transition">Contact</a>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/public/domain-search.blade.php ENDPATH**/ ?>