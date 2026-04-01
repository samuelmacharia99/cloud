@extends('layouts.app')

@section('title', 'Create Product')

@section('content')
<div class="max-w-2xl space-y-8">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Create New Product</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Add a new hosting product to your catalog.</p>
    </div>

    <form action="{{ route('products.store') }}" method="POST" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 space-y-6">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product Name</label>
            <input type="text" name="name" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g., Shared Hosting">
            @error('name') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Slug</label>
            <input type="text" name="slug" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g., shared-hosting">
            @error('slug') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Category</label>
            <input type="text" name="category" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g., Hosting">
            @error('category') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Description</label>
            <textarea name="description" rows="4" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="Product description..."></textarea>
        </div>

        <div class="grid grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Price ($)</label>
                <input type="number" name="price" step="0.01" min="0" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="0.00">
                @error('price') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Setup Fee ($)</label>
                <input type="number" name="setup_fee" step="0.01" min="0" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="0.00">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle</label>
            <select name="billing_cycle" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="semi-annual">Semi-Annual</option>
                <option value="annual">Annual</option>
            </select>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 dark:bg-blue-500 text-white font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                Create Product
            </button>
            <a href="{{ route('products.index') }}" class="px-6 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
