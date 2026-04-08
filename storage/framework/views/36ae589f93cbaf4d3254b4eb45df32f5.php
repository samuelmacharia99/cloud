<?php $__env->startSection('title', 'Checkout'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Checkout</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Review and place your order</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Order Items -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Items</h2>

                <div class="space-y-3">
                    <?php $__currentLoopData = $cartItems; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $item): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <div class="flex justify-between items-center p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                            <div>
                                <p class="font-medium text-slate-900 dark:text-white"><?php echo e($item['name']); ?></p>
                                <p class="text-sm text-slate-500 dark:text-slate-400"><?php echo e($item['description']); ?></p>
                            </div>
                            <p class="font-semibold text-slate-900 dark:text-white">Ksh <?php echo e(number_format($item['amount'], 0)); ?></p>
                        </div>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </div>
            </div>

            <!-- Container Product Configuration -->
            <?php
                $containerProducts = array_filter($cartItems, fn($item) => $item['type'] === 'container_hosting');
            ?>
            <?php if(!empty($containerProducts)): ?>
                <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                    <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Container Configuration</h2>

                    <div class="space-y-6">
                        <?php $__currentLoopData = $containerProducts; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <?php
                                $template = $product['container_template'] ?? null;
                            ?>
                            <?php if($template && $template->environment_variables): ?>
                                <div class="border-t border-slate-200 dark:border-slate-700 pt-6 first:border-t-0 first:pt-0">
                                    <h3 class="font-semibold text-slate-900 dark:text-white mb-4"><?php echo e($product['name']); ?></h3>

                                    <div class="space-y-4">
                                        <?php $__currentLoopData = $template->environment_variables; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $envVar): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                                            <?php
                                                $isRequired = $envVar['required'] ?? false;
                                                $isSecret = $envVar['secret'] ?? false;
                                                $fieldName = "env_values[{$key}][{$envVar['key']}]";
                                                $inputType = $isSecret ? 'password' : 'text';
                                            ?>

                                            <div>
                                                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">
                                                    <?php echo e($envVar['label'] ?? $envVar['key']); ?>

                                                    <?php if($isRequired): ?>
                                                        <span class="text-red-600 dark:text-red-400">*</span>
                                                    <?php endif; ?>
                                                </label>

                                                <input
                                                    type="<?php echo e($inputType); ?>"
                                                    name="<?php echo e($fieldName); ?>"
                                                    value="<?php echo e(old($fieldName, $envVar['default'] ?? '')); ?>"
                                                    placeholder="<?php echo e($envVar['default'] ?? ''); ?>"
                                                    <?php echo e($isRequired ? 'required' : ''); ?>

                                                    class="w-full px-4 py-2 bg-slate-50 dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded-lg text-slate-900 dark:text-white focus:ring-blue-500 focus:border-blue-500 transition"
                                                />

                                                <?php if(isset($envVar['description'])): ?>
                                                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?php echo e($envVar['description']); ?></p>
                                                <?php endif; ?>

                                                <?php $__errorArgs = [$fieldName];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                                                    <p class="text-red-600 dark:text-red-400 text-xs mt-1"><?php echo e($message); ?></p>
                                                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
                                            </div>
                                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Customer Info -->
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6">
                <h2 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Your Information</h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Full Name</label>
                        <p class="text-slate-900 dark:text-white font-medium"><?php echo e($user->name); ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Email Address</label>
                        <p class="text-slate-900 dark:text-white font-medium"><?php echo e($user->email); ?></p>
                    </div>
                    <?php if($user->phone): ?>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Phone Number</label>
                            <p class="text-slate-900 dark:text-white font-medium"><?php echo e($user->phone); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Payment Info -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6">
                <h2 class="text-lg font-bold text-blue-900 dark:text-blue-100 mb-2">Payment Method</h2>
                <p class="text-blue-800 dark:text-blue-200">
                    An invoice will be generated after you place your order. You can pay using M-Pesa, bank transfer, or other available payment methods.
                </p>
            </div>

            <!-- Terms -->
            <form action="<?php echo e(route('customer.checkout.process')); ?>" method="POST" x-data="{ agree: false }">
                <?php echo csrf_field(); ?>

                <label class="flex items-start gap-3 mb-6 cursor-pointer">
                    <input type="checkbox" name="agree_terms" value="1" required x-model="agree" class="mt-1 rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                    <span class="text-sm text-slate-600 dark:text-slate-400">
                        I agree to the Terms of Service and understand that an invoice will be generated after placing this order
                    </span>
                </label>

                <!-- Submit -->
                <div class="flex gap-3">
                    <a href="<?php echo e(route('customer.cart.index')); ?>" class="flex-1 px-6 py-3 text-center text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 font-medium transition">
                        Back to Cart
                    </a>
                    <button
                        type="submit"
                        :disabled="!agree"
                        :class="!agree ? 'opacity-50 cursor-not-allowed' : ''"
                        class="flex-1 px-6 py-3 bg-green-600 hover:bg-green-700 disabled:bg-slate-400 text-white rounded-lg font-medium transition"
                    >
                        Place Order
                    </button>
                </div>
            </form>
        </div>

        <!-- Order Summary Sidebar -->
        <div class="lg:col-span-1">
            <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-6 sticky top-4">
                <h3 class="text-lg font-bold text-slate-900 dark:text-white mb-4">Order Summary</h3>

                <div class="space-y-3 mb-4 pb-4 border-b border-slate-200 dark:border-slate-700">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-400">Subtotal</span>
                        <span class="font-medium text-slate-900 dark:text-white">Ksh <?php echo e(number_format($subtotal, 0)); ?></span>
                    </div>

                    <?php if($taxEnabled): ?>
                        <div class="flex justify-between text-sm">
                            <span class="text-slate-600 dark:text-slate-400">Tax (<?php echo e($taxRate); ?>%)</span>
                            <span class="font-medium text-slate-900 dark:text-white">Ksh <?php echo e(number_format($tax, 0)); ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex justify-between">
                    <span class="font-semibold text-slate-900 dark:text-white">Total</span>
                    <span class="text-2xl font-bold text-green-600 dark:text-green-400">Ksh <?php echo e(number_format($total, 0)); ?></span>
                </div>

                <div class="mt-6 p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                    <p class="text-xs text-slate-600 dark:text-slate-400">
                        <strong>Note:</strong> Services will be activated automatically once your invoice is marked as paid.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/checkout/index.blade.php ENDPATH**/ ?>