@extends('layouts.reseller')

@section('title', 'Edit Catalog Item')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('reseller.dashboard') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Dashboard</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('reseller.catalog.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">My Catalog</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Edit</p>
</div>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Catalog Item</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Update your product details and pricing.</p>
    </div>

    <!-- Form -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8">
        <form method="POST" action="{{ route('reseller.catalog.update', $catalogItem) }}" class="space-y-8">
            @csrf
            @method('PUT')

            <!-- Two-column layout -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Left Column -->
                <div class="space-y-6">
                    <!-- Name -->
                    <div>
                        <label for="name" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product Name</label>
                        <input type="text" id="name" name="name" value="{{ old('name', $catalogItem->name) }}" placeholder="Product name" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('name') border-red-500 @enderror" required>
                        @error('name')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Description <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <textarea id="description" name="description" rows="4" placeholder="Describe this product..." class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm resize-none">{{ old('description', $catalogItem->description) }}</textarea>
                        @error('description')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Type -->
                    <div>
                        <label for="type" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product Type</label>
                        <select id="type" name="type" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('type') border-red-500 @enderror" required>
                            <option value="">Select a type...</option>
                            @foreach($productTypes as $key => $label)
                                <option value="{{ $key }}" @selected(old('type', $catalogItem->type) === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('type')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Base Product (if applicable) -->
                    @if(!$catalogItem->isCustom())
                        <div class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                            <p class="text-xs font-semibold text-blue-900 dark:text-blue-300 uppercase tracking-wide mb-2">Based on Admin Product</p>
                            <p class="text-sm font-medium text-blue-900 dark:text-blue-300">{{ $catalogItem->adminProduct?->name }}</p>
                            <p class="text-xs text-blue-800 dark:text-blue-400 mt-2">
                                Wholesale: <span class="font-medium">${{ number_format($catalogItem->adminProduct?->wholesale_monthly_price, 2) }}/mo</span>
                            </p>
                        </div>
                    @endif

                    <!-- Active Toggle -->
                    <div>
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" name="is_active" value="1" class="w-4 h-4 text-blue-600 rounded" @checked(old('is_active', $catalogItem->is_active))>
                            <span class="text-sm text-slate-700 dark:text-slate-300">Active</span>
                        </label>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="space-y-6">
                    <!-- Monthly Price -->
                    <div>
                        <label for="monthly_price" class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Monthly Price <span class="text-xs font-normal text-slate-500 dark:text-slate-400">(optional)</span></label>
                        <div class="relative">
                            <span class="absolute left-4 top-2 text-slate-500 dark:text-slate-400 text-sm">$</span>
                            <input type="number" id="monthly_price" name="monthly_price" value="{{ old('monthly_price', $catalogItem->monthly_price) }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('monthly_price') border-red-500 @enderror">
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
                            <input type="number" id="yearly_price" name="yearly_price" value="{{ old('yearly_price', $catalogItem->yearly_price) }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('yearly_price') border-red-500 @enderror">
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
                            <input type="number" id="setup_fee" name="setup_fee" value="{{ old('setup_fee', $catalogItem->setup_fee) }}" placeholder="0.00" step="0.01" min="0" class="w-full pl-7 pr-4 py-2 border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 rounded-lg focus:ring-2 focus:ring-blue-500 dark:focus:ring-blue-400 text-slate-900 dark:text-white text-sm @error('setup_fee') border-red-500 @enderror">
                        </div>
                        @error('setup_fee')
                            <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Margin Summary -->
                    @if(!$catalogItem->isCustom() && $catalogItem->getMonthlyMargin() !== null)
                        <div class="p-4 bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 rounded-lg">
                            <p class="text-xs font-semibold text-emerald-900 dark:text-emerald-300 uppercase tracking-wide mb-2">Your Margin</p>
                            <p class="text-lg font-bold text-emerald-900 dark:text-emerald-300">
                                ${{ number_format($catalogItem->getMonthlyMargin(), 2) }} ({{ number_format($catalogItem->getMonthlyMarginPercent(), 1) }}%)
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Form Actions -->
            <div class="flex items-center justify-between pt-6 border-t border-slate-200 dark:border-slate-800">
                <a href="{{ route('reseller.catalog.index') }}" class="px-6 py-2 text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white font-medium transition">
                    Cancel
                </a>
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Save Changes
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
