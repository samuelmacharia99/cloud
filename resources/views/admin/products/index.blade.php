@extends('layouts.admin')

@section('title', 'Products')

@section('breadcrumb')
<p class="text-sm font-medium text-slate-600 dark:text-slate-400">Products</p>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Products</h1>
            <p class="text-slate-600 dark:text-slate-400 mt-1">Manage your product catalog and service offerings.</p>
        </div>
        <a href="{{ route('admin.products.create') }}" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-medium rounded-lg transition">
            <svg class="w-5 h-5 inline mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Add Product
        </a>
    </div>

    <!-- Filters -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6">
        <div class="flex flex-col gap-4">
            <!-- Type filter tabs -->
            <div>
                <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Product Type</p>
                <div class="flex flex-wrap gap-2">
                    <a href="{{ route('admin.products.index') }}" class="px-4 py-2 rounded-lg font-medium text-sm transition {{ !request('type') ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        All Products
                    </a>
                    <a href="{{ route('admin.products.index', ['type' => 'shared_hosting']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition {{ request('type') === 'shared_hosting' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        Shared Hosting
                    </a>
                    <a href="{{ route('admin.products.index', ['type' => 'container_hosting']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition {{ request('type') === 'container_hosting' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        Container
                    </a>
                    <a href="{{ route('admin.products.index', ['type' => 'ssl']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition {{ request('type') === 'ssl' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        SSL Certificate
                    </a>
                    <a href="{{ route('admin.products.index', ['type' => 'email_hosting']) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition {{ request('type') === 'email_hosting' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        Email Hosting
                    </a>
                </div>
            </div>

            <!-- Status filter -->
            <div>
                <p class="text-sm font-medium text-slate-700 dark:text-slate-300 mb-3">Status</p>
                <div class="flex gap-2">
                    <a href="{{ route('admin.products.index', request()->except('status')) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition {{ !request('status') ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        All Status
                    </a>
                    <a href="{{ route('admin.products.index', array_merge(request()->all(), ['status' => 'active'])) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition {{ request('status') === 'active' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        Active
                    </a>
                    <a href="{{ route('admin.products.index', array_merge(request()->all(), ['status' => 'inactive'])) }}" class="px-4 py-2 rounded-lg font-medium text-sm transition {{ request('status') === 'inactive' ? 'bg-blue-600 text-white' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
                        Inactive
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-slate-50 dark:bg-slate-800 border-b border-slate-200 dark:border-slate-800">
                    <tr>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Product Name</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Type</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Pricing</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Status</th>
                        <th class="px-6 py-4 text-left text-sm font-semibold text-slate-900 dark:text-white">Services</th>
                        <th class="px-6 py-4 text-right text-sm font-semibold text-slate-900 dark:text-white">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                    @forelse ($products as $product)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800 transition-colors">
                            <td class="px-6 py-4">
                                <div>
                                    <p class="font-medium text-slate-900 dark:text-white">{{ $product->name }}</p>
                                    <p class="text-xs text-slate-600 dark:text-slate-400">{{ $product->slug }}</p>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 dark:bg-blue-950 text-blue-700 dark:text-blue-300">
                                    {{ ucfirst(str_replace('_', ' ', $product->type)) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">
                                @if ($product->monthly_price && $product->yearly_price)
                                    {{ $currency?->symbol ?? 'KES' }}{{ number_format($product->monthly_price * ($currency?->exchange_rate ?? 1), 2) }} / mo, {{ $currency?->symbol ?? 'KES' }}{{ number_format($product->yearly_price * ($currency?->exchange_rate ?? 1), 2) }} / yr
                                @elseif ($product->monthly_price)
                                    {{ $currency?->symbol ?? 'KES' }}{{ number_format($product->monthly_price * ($currency?->exchange_rate ?? 1), 2) }} / month
                                @elseif ($product->yearly_price)
                                    {{ $currency?->symbol ?? 'KES' }}{{ number_format($product->yearly_price * ($currency?->exchange_rate ?? 1), 2) }} / year
                                @else
                                    Custom
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {{ $product->is_active ? 'bg-emerald-100 dark:bg-emerald-950 text-emerald-700 dark:text-emerald-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-400' }}">
                                    {{ $product->is_active ? 'Active' : 'Inactive' }}
                                </span>
                                @if ($product->featured)
                                    <span class="inline-flex items-center ml-2 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-950 text-amber-700 dark:text-amber-300">
                                        Featured
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm text-slate-900 dark:text-white font-medium">
                                {{ $product->services_count ?? 0 }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.products.show', $product) }}" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                                        View
                                    </a>
                                    <a href="{{ route('admin.products.edit', $product) }}" class="px-3 py-1.5 text-sm font-medium text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 transition">
                                        Edit
                                    </a>
                                    <form action="{{ route('admin.products.destroy', $product) }}" method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this product? This action cannot be undone.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 text-sm font-medium text-red-600 dark:text-red-400 hover:text-red-700 dark:hover:text-red-300 transition">
                                            Delete
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center">
                                <p class="text-slate-600 dark:text-slate-400">No products found.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $products->links() }}
    </div>
</div>
@endsection
