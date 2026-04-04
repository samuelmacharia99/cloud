@extends('layouts.admin')

@section('title', 'Edit: ' . $package->name)

@section('content')
<div class="max-w-2xl mx-auto">
    <!-- Header -->
    <div class="mb-6">
        <a href="{{ route('admin.reseller-packages.index') }}" class="text-blue-600 dark:text-blue-400 hover:underline text-sm mb-3 inline-block">
            ← Back to Packages
        </a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Edit Package: {{ $package->name }}</h1>
    </div>

    <!-- Form -->
    <form action="{{ route('admin.reseller-packages.update', $package) }}" method="POST" class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8 space-y-6">
        @csrf
        @method('PUT')

        <!-- Package Name -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Package Name</label>
            <input type="text" name="name" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" required value="{{ old('name', $package->name) }}">
            @error('name')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Description -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Description</label>
            <textarea name="description" rows="3" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">{{ old('description', $package->description) }}</textarea>
            @error('description')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Billing Cycle -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle</label>
            <div class="flex gap-4">
                <label class="flex items-center gap-2">
                    <input type="radio" name="billing_cycle" value="monthly" class="rounded border-slate-300" {{ old('billing_cycle', $package->billing_cycle) === 'monthly' ? 'checked' : '' }} required>
                    <span class="text-sm text-slate-700 dark:text-slate-300">Monthly</span>
                </label>
                <label class="flex items-center gap-2">
                    <input type="radio" name="billing_cycle" value="annually" class="rounded border-slate-300" {{ old('billing_cycle', $package->billing_cycle) === 'annually' ? 'checked' : '' }}>
                    <span class="text-sm text-slate-700 dark:text-slate-300">Annually</span>
                </label>
            </div>
            @error('billing_cycle')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Storage Space -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Storage Space (GB)</label>
            <input type="number" name="storage_space" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" min="1" max="10000" required value="{{ old('storage_space', $package->storage_space) }}">
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Amount of cloud storage space in gigabytes</p>
            @error('storage_space')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Max Users -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Maximum Users</label>
            <input type="number" name="max_users" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" min="1" max="1000" required value="{{ old('max_users', $package->max_users) }}">
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Number of user accounts allowed for this package</p>
            @error('max_users')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Price -->
        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Price (KES)</label>
            <input type="number" name="price" step="0.01" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" min="0" required value="{{ old('price', $package->price) }}">
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Price per billing cycle</p>
            @error('price')
                <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
            @enderror
        </div>

        <!-- Active Toggle -->
        <div>
            <label class="flex items-center gap-3">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1" class="rounded border-slate-300" {{ old('active', $package->active) ? 'checked' : '' }}>
                <span class="text-sm font-medium text-slate-900 dark:text-white">Active</span>
            </label>
            <p class="text-xs text-slate-600 dark:text-slate-400 mt-1">Inactive packages won't be available for purchase</p>
        </div>

        <!-- Form Actions -->
        <div class="flex gap-3 pt-6 border-t border-slate-200 dark:border-slate-800">
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium transition-colors">
                Update Package
            </button>
            <a href="{{ route('admin.reseller-packages.index') }}" class="px-6 py-2 border border-slate-300 dark:border-slate-700 text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 rounded-lg font-medium transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
