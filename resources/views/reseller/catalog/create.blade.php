@extends('layouts.reseller')

@section('title', 'Add to Catalog')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('reseller.catalog.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">My Catalog</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Add Product</p>
</div>
@endsection

@section('content')
<div class="space-y-6" x-data="catalogForm()">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Add to Catalog</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Choose from admin products or create custom ones.</p>
    </div>

    <!-- Mode Toggle -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="flex gap-4">
            <button @click="mode = 'admin'; $nextTick(() => selectProduct()); $nextTick(() => calculateMargin())" :class="{ 'bg-blue-600 text-white': mode === 'admin', 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300': mode !== 'admin' }" class="px-6 py-2 font-medium rounded-lg transition">
                Add from Admin Catalog
            </button>
            <button @click="mode = 'custom'" :class="{ 'bg-blue-600 text-white': mode === 'custom', 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300': mode !== 'custom' }" class="px-6 py-2 font-medium rounded-lg transition">
                Create Custom Product
            </button>
        </div>
    </div>

    <!-- Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('reseller.catalog.store') }}" class="space-y-8">
            @csrf

            <!-- Mode 1: Add from Admin Catalog -->
            <div x-show="mode === 'admin'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Left: Admin Product Selection -->
                    <div class="space-y-6">
                        <!-- Select Admin Product -->
                        <div>
                            <label for="product_id" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Select Product</label>
                            <select id="product_id" name="product_id" @change="selectProduct(); $nextTick(() => calculateMargin())" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('product_id') border-red-500 @enderror">
                                <option value="">Choose a product...</option>
                                @foreach($adminProducts as $product)
                                    <option value="{{ $product->id }}" @selected(old('product_id') == $product->id)>
                                        {{ $product->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('product_id')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Your Pricing -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Your Pricing</h3>

                            <!-- Monthly Price -->
                            <div>
                                <label for="monthly_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Monthly Price <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                                <div class="relative">
                                    <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                                    <input type="number" id="monthly_price" name="monthly_price" value="{{ old('monthly_price') }}" placeholder="0.00" step="0.01" min="0" @input="calculateMargin()" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('monthly_price') border-red-500 @enderror">
                                </div>
                                @error('monthly_price')
                                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Yearly Price -->
                            <div>
                                <label for="yearly_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Yearly Price <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                                <div class="relative">
                                    <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                                    <input type="number" id="yearly_price" name="yearly_price" value="{{ old('yearly_price') }}" placeholder="0.00" step="0.01" min="0" @input="calculateMargin()" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('yearly_price') border-red-500 @enderror">
                                </div>
                                @error('yearly_price')
                                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Setup Fee -->
                            <div>
                                <label for="setup_fee" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Setup Fee <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                                <div class="relative">
                                    <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                                    <input type="number" id="setup_fee" name="setup_fee" value="{{ old('setup_fee') }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('setup_fee') border-red-500 @enderror">
                                </div>
                                @error('setup_fee')
                                    <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <!-- Active Toggle -->
                        <div>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" name="is_active" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(old('is_active') === '1' || old('is_active') === true || !old())>
                                <span class="text-sm text-slate-700 dark:text-slate-300">Active</span>
                            </label>
                        </div>
                    </div>

                    <!-- Right: Admin Product Details & Margin Preview -->
                    <div class="space-y-6">
                        <!-- Admin Product Card -->
                        <div class="bg-slate-50 dark:bg-slate-800 rounded-lg p-6" x-show="selectedProduct">
                            <h3 class="text-sm font-semibold text-slate-900 dark:text-white mb-4">Admin Product Details</h3>

                            <div class="space-y-4 text-sm">
                                <div>
                                    <p class="text-slate-600 dark:text-slate-400 mb-1">Product Name</p>
                                    <p class="font-medium text-slate-900 dark:text-white" x-text="selectedProduct?.name || '—'"></p>
                                </div>

                                <div>
                                    <p class="text-slate-600 dark:text-slate-400 mb-1">Type</p>
                                    <p class="font-medium text-slate-900 dark:text-white" x-text="productTypeLabel(selectedProduct?.type) || '—'"></p>
                                </div>

                                <div class="pt-4 border-t border-slate-200 dark:border-slate-700">
                                    <p class="text-slate-600 dark:text-slate-400 mb-1">Your Cost (Wholesale)</p>
                                    <div class="space-y-1">
                                        <p class="font-medium text-slate-900 dark:text-white">
                                            <span x-show="selectedProduct?.wholesale_monthly_price" x-text="'$' + parseFloat(selectedProduct?.wholesale_monthly_price || 0).toFixed(2) + '/mo'"></span>
                                            <span x-show="!selectedProduct?.wholesale_monthly_price">—</span>
                                        </p>
                                        <p class="font-medium text-slate-900 dark:text-white">
                                            <span x-show="selectedProduct?.wholesale_yearly_price" x-text="'$' + parseFloat(selectedProduct?.wholesale_yearly_price || 0).toFixed(2) + '/yr'"></span>
                                            <span x-show="!selectedProduct?.wholesale_yearly_price">—</span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Margin Preview -->
                        <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg p-6" x-show="selectedProduct && (monthlyMargin !== null || yearlyMargin !== null)">
                            <h3 class="text-sm font-semibold text-emerald-900 dark:text-emerald-300 mb-4">Your Margin</h3>

                            <div class="space-y-2 text-sm">
                                <div x-show="monthlyMargin !== null">
                                    <p class="text-emerald-700 dark:text-emerald-400 mb-1">Monthly</p>
                                    <p class="text-lg font-bold text-emerald-900 dark:text-emerald-300">
                                        <span x-text="'$' + monthlyMargin.toFixed(2)"></span>
                                        <span x-text="'(' + monthlyMarginPercent.toFixed(1) + '%)'"></span>
                                    </p>
                                </div>

                                <div x-show="yearlyMargin !== null">
                                    <p class="text-emerald-700 dark:text-emerald-400 mb-1">Yearly</p>
                                    <p class="text-lg font-bold text-emerald-900 dark:text-emerald-300">
                                        <span x-text="'$' + yearlyMargin.toFixed(2)"></span>
                                        <span x-text="'(' + yearlyMarginPercent.toFixed(1) + '%)'"></span>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mode 2: Create Custom Product -->
            <div x-show="mode === 'custom'" class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="space-y-6">
                        <!-- Name -->
                        <div>
                            <label for="custom_name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product Name</label>
                            <input type="text" id="custom_name" x-model="customName" @input="updateCustomForm()" placeholder="My Custom Product" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('name') border-red-500 @enderror">
                            @error('name')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Description -->
                        <div>
                            <label for="custom_description" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Description <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <textarea id="custom_description" x-model="customDescription" @input="updateCustomForm()" rows="4" placeholder="Describe this product..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none @error('description') border-red-500 @enderror"></textarea>
                            @error('description')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Type -->
                        <div>
                            <label for="custom_type" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product Type</label>
                            <select id="custom_type" x-model="customType" @change="updateCustomForm()" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('type') border-red-500 @enderror">
                                <option value="">Select a type...</option>
                                @foreach($productTypes as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('type')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div class="space-y-6">
                        <!-- Monthly Price -->
                        <div>
                            <label for="custom_monthly_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Monthly Price <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <div class="relative">
                                <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                                <input type="number" id="custom_monthly_price" x-model.number="customMonthlyPrice" @input="updateCustomForm()" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('monthly_price') border-red-500 @enderror">
                            </div>
                            @error('monthly_price')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Yearly Price -->
                        <div>
                            <label for="custom_yearly_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Yearly Price <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <div class="relative">
                                <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                                <input type="number" id="custom_yearly_price" x-model.number="customYearlyPrice" @input="updateCustomForm()" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('yearly_price') border-red-500 @enderror">
                            </div>
                            @error('yearly_price')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Setup Fee -->
                        <div>
                            <label for="custom_setup_fee" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Setup Fee <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                            <div class="relative">
                                <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                                <input type="number" id="custom_setup_fee" x-model.number="customSetupFee" @input="updateCustomForm()" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('setup_fee') border-red-500 @enderror">
                            </div>
                            @error('setup_fee')
                                <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                            @enderror
                        </div>

                        <!-- Active Toggle -->
                        <div>
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" x-model="customIsActive" @change="updateCustomForm()" class="w-4 h-4 text-blue-600 rounded" checked>
                                <span class="text-sm text-slate-700 dark:text-slate-300">Active</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Hidden input for mode 2 -->
                <input type="hidden" name="product_id" value="">
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                <a href="{{ route('reseller.catalog.index') }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Add to Catalog
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function catalogForm() {
    return {
        mode: 'admin',
        selectedProduct: null,
        monthlyMargin: null,
        yearlyMargin: null,
        monthlyMarginPercent: null,
        yearlyMarginPercent: null,
        customName: '{{ old("name") }}',
        customDescription: '{{ old("description") }}',
        customType: '{{ old("type") }}',
        customMonthlyPrice: {{ old('monthly_price') ?? 'null' }},
        customYearlyPrice: {{ old('yearly_price') ?? 'null' }},
        customSetupFee: {{ old('setup_fee') ?? 'null' }},
        customIsActive: true,
        products: {
            @foreach($adminProducts as $product)
                {{ $product->id }}: @json($product),
            @endforeach
        },
        productTypes: @json($productTypes),
        selectProduct() {
            const productId = document.getElementById('product_id')?.value;
            this.selectedProduct = productId ? this.products[productId] : null;
        },
        calculateMargin() {
            if (!this.selectedProduct) {
                this.monthlyMargin = null;
                this.yearlyMargin = null;
                this.monthlyMarginPercent = null;
                this.yearlyMarginPercent = null;
                return;
            }

            const monthlyPrice = parseFloat(document.getElementById('monthly_price')?.value || 0);
            const yearlyPrice = parseFloat(document.getElementById('yearly_price')?.value || 0);
            const wholesaleMonthly = parseFloat(this.selectedProduct.wholesale_monthly_price || 0);
            const wholesaleYearly = parseFloat(this.selectedProduct.wholesale_yearly_price || 0);

            if (monthlyPrice > 0 && wholesaleMonthly > 0) {
                this.monthlyMargin = monthlyPrice - wholesaleMonthly;
                this.monthlyMarginPercent = (this.monthlyMargin / wholesaleMonthly) * 100;
            } else {
                this.monthlyMargin = null;
                this.monthlyMarginPercent = null;
            }

            if (yearlyPrice > 0 && wholesaleYearly > 0) {
                this.yearlyMargin = yearlyPrice - wholesaleYearly;
                this.yearlyMarginPercent = (this.yearlyMargin / wholesaleYearly) * 100;
            } else {
                this.yearlyMargin = null;
                this.yearlyMarginPercent = null;
            }
        },
        productTypeLabel(type) {
            return this.productTypes[type] || type;
        },
        updateCustomForm() {
            const form = document.querySelector('form');
            const inputs = {
                name: this.customName,
                description: this.customDescription,
                type: this.customType,
                monthly_price: this.customMonthlyPrice || '',
                yearly_price: this.customYearlyPrice || '',
                setup_fee: this.customSetupFee || '',
                is_active: this.customIsActive ? '1' : '0',
            };
        }
    }
}
</script>
@endsection
