<?php $__env->startSection('title', 'Confirm Techstack & Choose Package'); ?>

<?php $__env->startSection('content'); ?>
<div class="space-y-6" x-data="packageConfigurator()">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Choose Your Hosting Package</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Select a plan that matches your needs</p>
        </div>
        <a href="<?php echo e(route('customer.cart.index')); ?>" class="relative">
            <svg class="w-6 h-6 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            <?php if($cartCount > 0): ?>
                <span class="absolute -top-2 -right-2 bg-red-600 text-white text-xs font-bold rounded-full w-5 h-5 flex items-center justify-center"><?php echo e($cartCount); ?></span>
            <?php endif; ?>
        </a>
    </div>

    <!-- Techstack Summary -->
    <div class="bg-gradient-to-r from-blue-50 to-purple-50 dark:from-slate-800 dark:to-slate-900 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        <h3 class="font-semibold text-slate-900 dark:text-white mb-4">Your Selection</h3>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Language</p>
                <p class="font-semibold text-slate-900 dark:text-white"><?php echo e($language->name); ?></p>
            </div>
            <div>
                <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Database</p>
                <p class="font-semibold text-slate-900 dark:text-white"><?php echo e($database->name); ?></p>
            </div>
            <div>
                <p class="text-xs text-slate-600 dark:text-slate-400 mb-1">Hosting Type</p>
                <p class="font-semibold text-slate-900 dark:text-white"><?php echo e($routing['hosting_type'] === 'directadmin' ? '🌐 Shared Hosting' : '🐳 Container Hosting'); ?></p>
            </div>
            <div class="text-right">
                <a href="<?php echo e(route('customer.select-techstack')); ?>" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">Change →</a>
            </div>
        </div>
    </div>

    <!-- Available Packages Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <?php $__currentLoopData = $products; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $product): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
        <button
            type="button"
            @click="selectProduct(<?php echo e($product->id); ?>, '<?php echo e($product->name); ?>', <?php echo e($product->monthly_price); ?>)"
            class="relative group overflow-hidden rounded-xl border-2 transition-all duration-300 p-6 text-left hover:shadow-lg"
            :class="selectedProductId === <?php echo e($product->id); ?>

                ? 'border-blue-600 dark:border-blue-500 bg-blue-50 dark:bg-slate-800 shadow-lg'
                : 'border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 hover:border-blue-400 dark:hover:border-blue-600'"
        >
            <!-- Selected Badge -->
            <template x-if="selectedProductId === <?php echo e($product->id); ?>">
                <div class="absolute top-0 right-0 bg-blue-600 text-white px-3 py-1 text-xs font-semibold">SELECTED</div>
            </template>

            <!-- Plan Name -->
            <h3 class="text-xl font-bold text-slate-900 dark:text-white mb-2"><?php echo e($product->name); ?></h3>

            <!-- Price -->
            <div class="mb-4">
                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400">KES <?php echo e(number_format($product->monthly_price, 0)); ?></p>
                <p class="text-sm text-slate-600 dark:text-slate-400">per month</p>
            </div>

            <!-- Description -->
            <p class="text-sm text-slate-600 dark:text-slate-400 mb-4"><?php echo e($product->description); ?></p>

            <!-- Features -->
            <?php if($product->features && count($product->features) > 0): ?>
            <ul class="space-y-2 mb-4">
                <?php $__currentLoopData = array_slice($product->features, 0, 3); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $feature): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                <li class="text-sm text-slate-700 dark:text-slate-300 flex items-center gap-2">
                    <svg class="w-4 h-4 text-green-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                    </svg>
                    <?php echo e($feature); ?>

                </li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                <?php if(count($product->features) > 3): ?>
                <li class="text-sm text-slate-600 dark:text-slate-400">+ <?php echo e(count($product->features) - 3); ?> more features</li>
                <?php endif; ?>
            </ul>
            <?php endif; ?>

            <!-- Click Prompt -->
            <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                <p class="text-xs text-slate-500 dark:text-slate-400 group-hover:text-slate-700 dark:group-hover:text-slate-200 transition">Click to select this plan →</p>
            </div>
        </button>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
    </div>

    <!-- Add to Cart Section -->
    <template x-if="selectedProductId">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
        <h2 class="text-2xl font-bold text-slate-900 dark:text-white mb-6">Finalize Your Order</h2>

        <form action="<?php echo e(route('customer.cart.add')); ?>" method="POST" class="space-y-4" x-init="updatePrice()">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="type" value="product">
            <input type="hidden" name="product_id" :value="selectedProductId">
            <input type="hidden" name="billing_cycle" x-bind:value="cycle">
            <?php if($language->versions && count($language->versions) > 0): ?>
                <input type="hidden" name="version" x-bind:value="version">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- Version Selector -->
                <?php if($language->versions && count($language->versions) > 0): ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2"><?php echo e($language->name); ?> Version</label>
                    <select x-model="version" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        <?php $__currentLoopData = $language->versions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $version): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <option value="<?php echo e($version); ?>">v<?php echo e($version); ?></option>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Billing Cycle -->
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">Billing Cycle</label>
                    <select x-model="cycle" @change="updatePrice()" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white">
                        <option value="monthly">Monthly</option>
                        <option value="quarterly">Quarterly (Save 2%)</option>
                        <option value="semi-annual">Semi-Annual (Save 5%)</option>
                        <option value="annual">Annual (Save 8%)</option>
                    </select>
                </div>

                <!-- Summary with Dynamic Pricing -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border-2 border-blue-200 dark:border-blue-700 rounded-lg p-4">
                    <p class="text-xs text-blue-700 dark:text-blue-400 mb-1">Total Amount Due</p>
                    <p class="text-3xl font-bold text-blue-600 dark:text-blue-400" x-text="'KES ' + formatPrice(calculatedPrice)"></p>
                    <p class="text-sm text-blue-700 dark:text-blue-300 mt-2" x-text="getPricingLabel()"></p>
                    <template x-if="discountAmount > 0">
                        <p class="text-sm text-green-700 dark:text-green-400 mt-1 font-semibold">You save KES <span x-text="formatPrice(discountAmount)"></span></p>
                    </template>
                </div>
            </div>

            <!-- Pricing Breakdown -->
            <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-4 space-y-2">
                <div class="flex justify-between text-sm">
                    <span class="text-slate-600 dark:text-slate-400">Base Price (Monthly)</span>
                    <span class="font-semibold text-slate-900 dark:text-white">KES <span x-text="formatPrice(basePrice)"></span></span>
                </div>
                <template x-if="billingMonths > 1">
                    <div class="flex justify-between text-sm">
                        <span class="text-slate-600 dark:text-slate-400" x-text="`${billingMonths} months × base price`"></span>
                        <span class="font-semibold text-slate-900 dark:text-white">KES <span x-text="formatPrice(basePrice * billingMonths)"></span></span>
                    </div>
                    <div class="flex justify-between text-sm border-t border-slate-200 dark:border-slate-700 pt-2">
                        <span class="text-slate-600 dark:text-slate-400">Discount Applied</span>
                        <span class="font-semibold text-green-700 dark:text-green-400" x-text="`-KES ${formatPrice(discountAmount)}`"></span>
                    </div>
                </template>
                <div class="flex justify-between text-base font-bold border-t border-slate-300 dark:border-slate-600 pt-2 mt-2">
                    <span class="text-slate-900 dark:text-white">Total Due</span>
                    <span class="text-blue-600 dark:text-blue-400" x-text="'KES ' + formatPrice(calculatedPrice)"></span>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-3 pt-4">
                <button type="submit" class="flex-1 px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-semibold transition">
                    Add to Cart
                </button>
                <a href="<?php echo e(route('customer.select-techstack')); ?>" class="px-6 py-3 border-2 border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 rounded-lg font-semibold hover:bg-slate-100 dark:hover:bg-slate-800 transition">
                    Change Techstack
                </a>
            </div>
        </form>
    </div>
    </template>

    <!-- No Selection Placeholder -->
    <template x-if="!selectedProductId">
    <div class="bg-slate-50 dark:bg-slate-800 rounded-xl border-2 border-dashed border-slate-300 dark:border-slate-600 p-12 text-center">
        <p class="text-slate-600 dark:text-slate-400 text-lg">Select a package above to continue</p>
    </div>
    </template>
</div>

<script>
function packageConfigurator() {
    return {
        // Package Selection
        selectedProductId: null,
        selectedProductName: '',
        selectedProductPrice: 0,

        // Order Configuration
        cycle: 'monthly',
        version: '<?php echo e($language->versions[0] ?? ''); ?>',
        basePrice: 0,
        calculatedPrice: 0,
        discountAmount: 0,
        billingMonths: 1,

        // Select a product
        selectProduct(productId, productName, productPrice) {
            this.selectedProductId = productId;
            this.selectedProductName = productName;
            this.selectedProductPrice = productPrice;
            this.basePrice = productPrice;
            this.cycle = 'monthly'; // Reset to monthly when selecting new product
            this.updatePrice();
        },

        // Update price based on billing cycle
        updatePrice() {
            const discounts = {
                'monthly': { months: 1, rate: 0 },
                'quarterly': { months: 3, rate: 0.02 },
                'semi-annual': { months: 6, rate: 0.05 },
                'annual': { months: 12, rate: 0.08 }
            };

            const config = discounts[this.cycle] || discounts['monthly'];
            this.billingMonths = config.months;

            // Calculate subtotal (months × base price)
            const subtotal = this.basePrice * config.months;

            // Apply discount
            const discount = subtotal * config.rate;
            this.discountAmount = discount;
            this.calculatedPrice = subtotal - discount;
        },

        // Format price with thousands separator
        formatPrice(amount) {
            return Math.round(amount).toLocaleString('en-US');
        },

        // Get billing period label
        getPricingLabel() {
            const labels = {
                'monthly': 'Per month',
                'quarterly': 'Per 3 months',
                'semi-annual': 'Per 6 months',
                'annual': 'Per year'
            };
            return labels[this.cycle] || 'Per month';
        }
    };
}
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.customer', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH /home/zumi/php/road-map/talksasa-cloud/resources/views/customer/confirm-techstack.blade.php ENDPATH**/ ?>