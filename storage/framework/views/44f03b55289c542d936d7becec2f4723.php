<!DOCTYPE html>
<html lang="<?php echo e(str_replace('_', '-', app()->getLocale())); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?php echo e(csrf_token()); ?>">
    <title>Checkout — <?php echo e(config('app.name', 'Talksasa Cloud')); ?></title>
    <?php echo app('Illuminate\Foundation\Vite')(['resources/css/app.css', 'resources/js/app.js']); ?>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        :root {
            --cyan-bright: #00D9FF;
            --cyan-dark: #0099CC;
            --neon-green: #00FF88;
            --dark-bg: #0F172A;
            --dark-card: #1E293B;
            --dark-border: #334155;
        }

        body {
            background: linear-gradient(135deg, #0F172A 0%, #1a1f3a 50%, #0F172A 100%);
            color: #e2e8f0;
        }

        .glow-cyan {
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.3), inset 0 0 20px rgba(0, 217, 255, 0.1);
        }

        .text-gradient {
            background: linear-gradient(135deg, #ffffff 0%, var(--cyan-bright) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .accent-green {
            color: var(--neon-green);
        }

        .btn-cyan {
            background: linear-gradient(135deg, var(--cyan-bright) 0%, #0099CC 100%);
            color: #0F172A;
            font-weight: 600;
            padding: 0.75rem 2rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 0 20px rgba(0, 217, 255, 0.3);
        }

        .btn-cyan:hover {
            box-shadow: 0 0 30px rgba(0, 217, 255, 0.5);
            transform: translateY(-2px);
        }

        .card-dark {
            background: rgba(30, 41, 59, 0.8);
            border: 1px solid rgba(0, 217, 255, 0.2);
            border-radius: 1rem;
            backdrop-filter: blur(10px);
        }

        .input-dark {
            background: rgba(30, 41, 59, 0.6);
            border: 1px solid rgba(0, 217, 255, 0.2);
            color: #e2e8f0;
            padding: 0.875rem 1rem;
            border-radius: 0.5rem;
            transition: all 0.3s ease;
        }

        .input-dark:focus {
            outline: none;
            border-color: var(--cyan-bright);
            box-shadow: 0 0 15px rgba(0, 217, 255, 0.3);
            background: rgba(30, 41, 59, 0.8);
        }

        .nav-link {
            color: #cbd5e1;
            transition: color 0.3s ease;
            text-decoration: none;
        }

        .nav-link:hover {
            color: var(--cyan-bright);
        }
    </style>
</head>
<body class="bg-[#0F172A]" x-data="checkoutApp()">
    <!-- Navigation -->
    <nav class="fixed w-full top-0 z-50 bg-[#0F172A]/90 backdrop-blur-lg border-b border-[rgba(0,217,255,0.1)]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="/" class="flex items-center gap-3 hover:opacity-75 transition">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-cyan-400 to-cyan-600 flex items-center justify-center glow-cyan-sm">
                        <span class="text-[#0F172A] font-bold text-sm">TC</span>
                    </div>
                    <span class="text-xl font-bold text-gradient">Talksasa</span>
                </a>
            </div>

            <div class="flex items-center gap-4">
                <?php if(auth()->guard()->check()): ?>
                    <span class="text-slate-300"><?php echo e(auth()->user()->name); ?></span>
                <?php else: ?>
                    <span class="text-slate-400">Create Account & Checkout</span>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <section class="pt-32 pb-20 px-4 sm:px-6 lg:px-8 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <div class="grid md:grid-cols-3 gap-8">
                <!-- Left: Order Summary & Account Form -->
                <div class="md:col-span-2 space-y-8">
                    <!-- Order Summary -->
                    <div class="card-dark p-8">
                        <h2 class="text-2xl font-bold text-white mb-6">Order Summary</h2>

                        <div class="space-y-4">
                            <?php $__currentLoopData = $cartItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                <div class="flex justify-between items-start border-b border-[rgba(0,217,255,0.1)] pb-4 last:border-0">
                                    <div class="flex-1">
                                        <p class="font-semibold text-white"><?php echo e($item['name']); ?></p>
                                        <p class="text-sm text-slate-400"><?php echo e($item['description'] ?? ''); ?></p>
                                    </div>
                                    <div class="flex items-center gap-4">
                                        <p class="text-lg font-bold text-cyan-400">KES <?php echo e(number_format($item['amount'], 0)); ?></p>
                                        <button
                                            @click="removeItem('<?php echo e($item['full_domain'] ?? $item['name']); ?>')"
                                            class="text-slate-400 hover:text-red-400 transition-colors flex items-center justify-center w-6 h-6 flex-shrink-0"
                                            title="Remove item"
                                        >
                                            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                        </div>
                    </div>

                    <!-- Account Creation Form (for unauthenticated users) -->
                    <?php if(auth()->guard()->guest()): ?>
                        <div class="card-dark p-8">
                            <h2 class="text-2xl font-bold text-white mb-2">Create Your Account</h2>
                            <p class="text-slate-400 mb-6">We'll use this information to manage your domains and services</p>

                            <form action="<?php echo e(route('checkout.process.public')); ?>" method="POST" class="space-y-6">
                                <?php echo csrf_field(); ?>

                                <!-- Name -->
                                <div>
                                    <label for="name" class="block text-sm font-medium text-white mb-2">Full Name</label>
                                    <input
                                        type="text"
                                        id="name"
                                        name="name"
                                        value="<?php echo e(old('name')); ?>"
                                        class="input-dark w-full <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                                        required
                                    >
                                    <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                                </div>

                                <!-- Email -->
                                <div>
                                    <label for="email" class="block text-sm font-medium text-white mb-2">Email Address</label>
                                    <input
                                        type="email"
                                        id="email"
                                        name="email"
                                        value="<?php echo e(old('email')); ?>"
                                        class="input-dark w-full <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                                        required
                                    >
                                    <?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                                </div>

                                <!-- Password -->
                                <div>
                                    <label for="password" class="block text-sm font-medium text-white mb-2">Password</label>
                                    <input
                                        type="password"
                                        id="password"
                                        name="password"
                                        class="input-dark w-full <?php $__errorArgs = ['password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                                        required
                                    >
                                    <p class="mt-1 text-sm text-slate-500">At least 8 characters</p>
                                    <?php $__errorArgs = ['password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                        <p class="mt-1 text-sm text-red-400"><?php echo e($message); ?></p>
                                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                                </div>

                                <!-- Password Confirmation -->
                                <div>
                                    <label for="password_confirmation" class="block text-sm font-medium text-white mb-2">Confirm Password</label>
                                    <input
                                        type="password"
                                        id="password_confirmation"
                                        name="password_confirmation"
                                        class="input-dark w-full"
                                        required
                                    >
                                </div>

                                <!-- Terms -->
                                <div class="bg-[rgba(0,217,255,0.1)] border border-[rgba(0,217,255,0.2)] rounded-lg p-4">
                                    <label class="flex items-start gap-3 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="agree_terms"
                                            class="mt-1 <?php $__errorArgs = ['agree_terms'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?> border-red-500 <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>"
                                            required
                                        >
                                        <span class="text-sm text-slate-300">
                                            I agree to the Terms of Service and Privacy Policy. I authorize the charge for this order.
                                        </span>
                                    </label>
                                    <?php $__errorArgs = ['agree_terms'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                        <p class="mt-2 text-sm text-red-400"><?php echo e($message); ?></p>
                                    <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                                </div>

                                <!-- Submit Button -->
                                <button
                                    type="submit"
                                    class="btn-cyan w-full"
                                >
                                    Create Account & Complete Order
                                </button>

                                <!-- Already have account -->
                                <p class="text-center text-slate-400">
                                    Already have an account? <a href="<?php echo e(route('login')); ?>" class="text-cyan-400 hover:text-cyan-300 font-semibold">Sign in</a>
                                </p>
                            </form>
                        </div>
                    <?php endif; ?>

                    <?php if(auth()->guard()->check()): ?>
                        <!-- Authenticated user checkout -->
                        <div class="card-dark p-8">
                            <h2 class="text-2xl font-bold text-white mb-6">Complete Your Order</h2>

                            <form action="<?php echo e(route('checkout.process.public')); ?>" method="POST" class="space-y-6">
                                <?php echo csrf_field(); ?>

                                <!-- Terms -->
                                <div class="bg-[rgba(0,217,255,0.1)] border border-[rgba(0,217,255,0.2)] rounded-lg p-4 mb-6">
                                    <label class="flex items-start gap-3 cursor-pointer">
                                        <input
                                            type="checkbox"
                                            name="agree_terms"
                                            class="mt-1"
                                            required
                                        >
                                        <span class="text-sm text-slate-300">
                                            I authorize the charge for this order.
                                        </span>
                                    </label>
                                </div>

                                <!-- Submit Button -->
                                <button
                                    type="submit"
                                    class="btn-cyan w-full"
                                >
                                    Complete Order
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right: Order Total -->
                <div>
                    <div class="card-dark p-8 sticky top-32">
                        <h3 class="text-xl font-bold text-white mb-6">Order Total</h3>

                        <div class="space-y-4 mb-6 pb-6 border-b border-[rgba(0,217,255,0.1)]">
                            <div class="flex justify-between text-slate-300">
                                <span>Subtotal</span>
                                <span class="font-semibold">KES <?php echo e(number_format($subtotal, 0)); ?></span>
                            </div>
                            <?php if($taxEnabled && $tax > 0): ?>
                                <div class="flex justify-between text-slate-300">
                                    <span>Tax (<?php echo e($taxRate); ?>%)</span>
                                    <span class="font-semibold">KES <?php echo e(number_format($tax, 0)); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="flex justify-between items-center mb-8">
                            <span class="text-lg font-bold text-white">Total</span>
                            <span class="text-3xl font-bold text-gradient">KES <?php echo e(number_format($total, 0)); ?></span>
                        </div>

                        <div class="pt-6 border-t border-[rgba(0,217,255,0.1)]">
                            <div class="space-y-2 text-sm text-slate-400">
                                <p>✓ Secure checkout</p>
                                <p>✓ Invoice will be generated</p>
                                <p>✓ Instant activation</p>
                            </div>
                        </div>

                        <!-- Back Link -->
                        <a href="/" class="block mt-6 text-center text-cyan-400 hover:text-cyan-300 font-semibold">
                            ← Continue Shopping
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-[rgba(0,217,255,0.1)] py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center text-sm text-slate-400">
                <p>&copy; 2026 Talksasa Cloud. All rights reserved.</p>
                <div class="flex gap-6">
                    <a href="#" class="nav-link">Privacy</a>
                    <a href="#" class="nav-link">Terms</a>
                    <a href="#" class="nav-link">Support</a>
                </div>
            </div>
        </div>
    </footer>

    <script>
        function checkoutApp() {
            return {
                removeItem(itemName) {
                    // Remove from localStorage cart
                    const cart = JSON.parse(localStorage.getItem('domainCart') || '[]');
                    const filtered = cart.filter(d => d.full_domain !== itemName);
                    localStorage.setItem('domainCart', JSON.stringify(filtered));

                    // Re-sync cart to session
                    fetch('<?php echo e(route("checkout.sync-cart")); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ cart: filtered })
                    }).then(response => {
                        if (response.ok) {
                            // Reload the page to show updated cart
                            window.location.reload();
                        }
                    }).catch(error => {
                        console.error('Error removing item:', error);
                        alert('Failed to remove item');
                    });
                }
            }
        }
    </script>
</body>
</html>
<?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/public/checkout.blade.php ENDPATH**/ ?>