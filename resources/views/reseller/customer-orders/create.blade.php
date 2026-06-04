@extends('layouts.reseller')

@section('title', 'Bill from Catalog')

@section('content')
<div class="space-y-6 max-w-xl">
    <div>
        <a href="{{ route('reseller.catalog.index') }}" class="text-sm text-purple-600">← Catalog</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Bill customer from catalog</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Creates an invoice using your catalog retail prices.</p>
    </div>

    <form method="POST" action="{{ route('reseller.customer-orders.store') }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium mb-2">Customer</label>
            <select name="customer_id" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" @selected(old('customer_id', $selectedCustomer?->id) == $customer->id)>{{ $customer->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Catalog product</label>
            <select name="reseller_product_id" required class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
                @forelse ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }} — mo KES {{ number_format($product->monthly_price ?? 0, 2) }}</option>
                @empty
                    <option value="">No active catalog items</option>
                @endforelse
            </select>
            @if ($products->isEmpty())
                <p class="text-sm text-amber-600 mt-2"><a href="{{ route('reseller.catalog.create') }}" class="underline">Add catalog products</a> first.</p>
            @endif
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Billing cycle</label>
            <select name="billing_cycle" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
                @foreach (['monthly', 'quarterly', 'semi-annual', 'annual'] as $cycle)
                    <option value="{{ $cycle }}" @selected(old('billing_cycle', 'monthly') === $cycle)>{{ ucfirst($cycle) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Invoice status</label>
            <select name="status" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
                <option value="unpaid">Unpaid</option>
                <option value="draft">Draft</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Due date</label>
            <input type="date" name="due_date" value="{{ old('due_date', now()->addDays(7)->format('Y-m-d')) }}" class="w-full px-4 py-2 border border-slate-300 dark:border-slate-600 rounded-lg bg-white dark:bg-slate-800">
        </div>

        <button type="submit" @disabled($products->isEmpty()) class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 disabled:opacity-50 text-white rounded-lg font-medium">Create invoice</button>
    </form>
</div>
@endsection
