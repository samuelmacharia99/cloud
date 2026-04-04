@extends('layouts.admin')

@section('title', 'Create Service')

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.services.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Services</a>
    <span class="text-slate-400">/</span>
    <p class="text-slate-600 dark:text-slate-400">Create New</p>
</div>
@endsection

@section('content')
<div class="max-w-2xl mx-auto">
    <div class="bg-white dark:bg-slate-900 rounded-xl border border-slate-200 dark:border-slate-800 p-8">
        <h1 class="text-2xl font-bold text-slate-900 dark:text-white mb-6">Create New Service</h1>

        <form action="{{ route('admin.services.store') }}" method="POST" x-data="{ productType: '', showDomainField: false }">
            @csrf

            <!-- Customer Selection -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Customer *</label>
                <select name="user_id" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <option value="">Select a customer...</option>
                    @foreach ($customers as $customer)
                        <option value="{{ $customer->id }}" {{ old('user_id') == $customer->id ? 'selected' : '' }}>
                            {{ $customer->name }} ({{ $customer->email }})
                        </option>
                    @endforeach
                </select>
                @error('user_id')
                    <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Product Selection -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Product *</label>
                <select name="product_id" x-model="productType" @change="showDomainField = productType && products.find(p => p.id == productType)?.type === 'domain'" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <option value="">Select a product...</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" data-type="{{ $product->type }}" {{ old('product_id') == $product->id ? 'selected' : '' }}>
                            {{ $product->name }} ({{ \App\Models\Product::typeLabel($product->type) }})
                        </option>
                    @endforeach
                </select>
                @error('product_id')
                    <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Service Name -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Service Name *</label>
                <input type="text" name="name" required value="{{ old('name') }}" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="e.g., My Web Hosting">
                @error('name')
                    <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Domain Name (for domain products) -->
            <div class="mb-6" x-show="showDomainField">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Domain Name</label>
                <input type="text" name="custom_domain" value="{{ old('custom_domain') }}" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="e.g., example.com">
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">Leave blank to use service name</p>
            </div>

            <!-- Billing Cycle -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Billing Cycle *</label>
                <select name="billing_cycle" required class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    <option value="monthly" {{ old('billing_cycle') == 'monthly' ? 'selected' : '' }}>Monthly</option>
                    <option value="quarterly" {{ old('billing_cycle') == 'quarterly' ? 'selected' : '' }}>Quarterly</option>
                    <option value="semi-annual" {{ old('billing_cycle') == 'semi-annual' ? 'selected' : '' }}>Semi-Annual</option>
                    <option value="annual" {{ old('billing_cycle') == 'annual' ? 'selected' : '' }}>Annual</option>
                </select>
                @error('billing_cycle')
                    <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Next Due Date -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Next Due Date *</label>
                <input type="date" name="next_due_date" required value="{{ old('next_due_date', now()->addMonths(1)->format('Y-m-d')) }}" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                @error('next_due_date')
                    <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Notes -->
            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-2">Notes</label>
                <textarea name="notes" rows="3" class="w-full px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:border-blue-500 focus:ring-1 focus:ring-blue-500" placeholder="Additional notes...">{{ old('notes') }}</textarea>
                @error('notes')
                    <p class="text-red-600 dark:text-red-400 text-xs mt-1">{{ $message }}</p>
                @enderror
            </div>

            <!-- Options -->
            <div class="space-y-4 mb-8 p-4 bg-slate-50 dark:bg-slate-800 rounded-lg">
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="generate_invoice" value="1" {{ old('generate_invoice') ? 'checked' : '' }} class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Generate Invoice</span>
                </label>
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="provision_now" value="1" {{ old('provision_now') ? 'checked' : '' }} class="rounded border-slate-300 dark:border-slate-600 focus:ring-blue-500">
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">Provision Immediately</span>
                </label>
            </div>

            <!-- Submit -->
            <div class="flex gap-3">
                <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
                    Create Service
                </button>
                <a href="{{ route('admin.services.index') }}" class="px-6 py-2 text-slate-700 dark:text-slate-300 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const productSelect = document.querySelector('select[name="product_id"]');
        const products = @json($products);

        productSelect.addEventListener('change', function() {
            const selected = products.find(p => p.id == this.value);
            document.querySelector('[x-show="showDomainField"]').style.display =
                selected && selected.type === 'domain' ? 'block' : 'none';
        });
    });
</script>
@endpush
@endsection
