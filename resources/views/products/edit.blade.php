@extends('layouts.app')

@section('title', 'Edit Product')

@section('content')
<div class="max-w-2xl space-y-8">
    <div>
        <h1 class="text-3xl font-bold text-slate-900">Edit Product</h1>
        <p class="text-slate-600 mt-1">Update product details and pricing.</p>
    </div>

    <form action="{{ route('products.update', $product) }}" method="POST" class="bg-white rounded-2xl border border-slate-200 p-8 space-y-6">
        @csrf
        @method('PUT')

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Product Name</label>
            <input type="text" name="name" value="{{ $product->name }}" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            @error('name') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Slug</label>
            <input type="text" name="slug" value="{{ $product->slug }}" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            @error('slug') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Category</label>
            <input type="text" name="category" value="{{ $product->category }}" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            @error('category') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Description</label>
            <textarea name="description" rows="4" class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">{{ $product->description }}</textarea>
        </div>

        <div class="grid grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-medium text-slate-900 mb-2">Price ($)</label>
                <input type="number" name="price" step="0.01" min="0" value="{{ $product->price }}" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                @error('price') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-900 mb-2">Setup Fee ($)</label>
                <input type="number" name="setup_fee" step="0.01" min="0" value="{{ $product->setup_fee }}" class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-900 mb-2">Billing Cycle</label>
            <select name="billing_cycle" required class="w-full px-4 py-2 rounded-lg border border-slate-300 bg-white text-slate-900 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <option value="monthly" {{ $product->billing_cycle === 'monthly' ? 'selected' : '' }}>Monthly</option>
                <option value="quarterly" {{ $product->billing_cycle === 'quarterly' ? 'selected' : '' }}>Quarterly</option>
                <option value="semi-annual" {{ $product->billing_cycle === 'semi-annual' ? 'selected' : '' }}>Semi-Annual</option>
                <option value="annual" {{ $product->billing_cycle === 'annual' ? 'selected' : '' }}>Annual</option>
            </select>
        </div>

        <div class="flex gap-4">
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-blue-600 text-white font-medium hover:bg-blue-700 transition-colors">
                Update Product
            </button>
            <a href="{{ route('products.show', $product) }}" class="px-6 py-2.5 rounded-lg border border-slate-300 text-slate-700 font-medium hover:bg-slate-50 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection
