@extends('layouts.admin')

@section('title', 'Delete '.$product->name)

@section('breadcrumb')
<div class="flex items-center gap-2 text-sm">
    <a href="{{ route('admin.products.index') }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">Products</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <a href="{{ route('admin.products.show', $product) }}" class="text-slate-600 dark:text-slate-400 hover:text-slate-900 dark:hover:text-white">{{ $product->name }}</a>
    <span class="text-slate-400 dark:text-slate-600">/</span>
    <p class="text-slate-600 dark:text-slate-400 font-medium">Delete</p>
</div>
@endsection

@section('content')
<div class="max-w-2xl space-y-6">
    <div>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white">Delete product</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Move existing customers to another package, then remove this product.</p>
    </div>

    <div class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-4">
        <div>
            <p class="text-sm text-slate-500 dark:text-slate-400">Product</p>
            <p class="text-lg font-semibold text-slate-900 dark:text-white">{{ $product->name }}</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">{{ ucfirst(str_replace('_', ' ', $product->type)) }}</p>
        </div>

        <ul class="text-sm text-slate-700 dark:text-slate-300 space-y-1 list-disc list-inside">
            <li><strong>{{ $servicesCount }}</strong> active/linked service(s) must be moved</li>
            @if($invoiceItemsCount > 0)
                <li><strong>{{ $invoiceItemsCount }}</strong> invoice line(s) will point at the replacement</li>
            @endif
            @if($orderItemsCount > 0)
                <li><strong>{{ $orderItemsCount }}</strong> order line(s) will point at the replacement</li>
            @endif
        </ul>

        @if($candidates->isEmpty())
            <div class="rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30 p-4 text-sm text-amber-900 dark:text-amber-100">
                <p class="font-medium">No replacement {{ str_replace('_', ' ', $product->type) }} package exists.</p>
                <p class="mt-1">Create another product of the same type first, or deactivate this product instead of deleting it.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('admin.products.create', ['type' => $product->type]) }}" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg text-sm font-medium">Create package</a>
                    <a href="{{ route('admin.products.edit', $product) }}" class="px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg text-sm font-medium text-slate-700 dark:text-slate-300">Edit / deactivate</a>
                </div>
            </div>
        @else
            <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="space-y-4">
                @csrf
                @method('DELETE')

                <div>
                    <label for="replacement_product_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">
                        Move services to this package
                    </label>
                    <select
                        name="replacement_product_id"
                        id="replacement_product_id"
                        required
                        class="w-full px-4 py-2.5 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-900 dark:text-white focus:ring-2 focus:ring-blue-500"
                    >
                        <option value="">Select a package…</option>
                        @foreach($candidates as $candidate)
                            <option value="{{ $candidate->id }}" @selected(old('replacement_product_id') == $candidate->id)>
                                {{ $candidate->name }}
                                @if(!$candidate->is_active) (inactive) @endif
                                — KES {{ number_format((float) ($candidate->monthly_price ?? 0), 0) }}/mo
                            </option>
                        @endforeach
                    </select>
                    @error('replacement_product_id')
                        <p class="text-sm text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                        Customer services keep their billing dates and prices unless you change them later. Only the linked product/package changes.
                    </p>
                </div>

                <div class="flex flex-wrap gap-3 pt-2">
                    <button type="submit" class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white font-medium rounded-lg transition">
                        Move services &amp; delete product
                    </button>
                    <a href="{{ route('admin.products.index') }}" class="px-5 py-2.5 border border-slate-300 dark:border-slate-600 text-slate-700 dark:text-slate-300 font-medium rounded-lg hover:bg-slate-50 dark:hover:bg-slate-800 transition">
                        Cancel
                    </a>
                </div>
            </form>
        @endif
    </div>
</div>
@endsection
