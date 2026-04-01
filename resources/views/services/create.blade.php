@extends('layouts.app')

@section('title', 'Create Service')

@section('content')
<div class="max-w-2xl space-y-8">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Create New Service</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Provision a new service for a customer.</p>
    </div>

    <form action="{{ route('services.store') }}" method="POST" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-8 space-y-6">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Customer</label>
            <select name="user_id" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="">Select a customer...</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>
                @endforeach
            </select>
            @error('user_id') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Product</label>
            <select name="product_id" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="">Select a product...</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }} - ${{ number_format($product->price, 2) }}/{{ $product->billing_cycle }}</option>
                @endforeach
            </select>
            @error('product_id') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Service Name</label>
            <input type="text" name="name" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent" placeholder="e.g., example.com Hosting">
            @error('name') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Billing Cycle</label>
            <select name="billing_cycle" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="monthly">Monthly</option>
                <option value="quarterly">Quarterly</option>
                <option value="semi-annual">Semi-Annual</option>
                <option value="annual">Annual</option>
            </select>
            @error('billing_cycle') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 dark:text-white mb-2">Next Due Date</label>
            <input type="date" name="next_due_date" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            @error('next_due_date') <p class="text-red-600 dark:text-red-400 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div class="flex gap-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 dark:bg-blue-500 text-white font-medium hover:bg-blue-700 dark:hover:bg-blue-600 transition-colors">
                Create Service
            </button>
            <a href="{{ route('services.index') }}" class="px-6 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
