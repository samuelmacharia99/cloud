@extends('layouts.reseller')

@section('title', 'Order Hosting for Customer')

@section('content')
<div class="space-y-6 max-w-xl">
    <div>
        <a href="{{ route('reseller.customer-invoices.index') }}" class="text-sm text-purple-600">← Customer billing</a>
        <h1 class="text-3xl font-bold text-slate-900 dark:text-white mt-2">Order hosting for customer</h1>
        <p class="text-slate-600 dark:text-slate-400 mt-1">Creates a pending service and customer invoice at your whitelabel retail price. Provisioning runs when the invoice is paid.</p>
    </div>

    <form method="POST" action="{{ route('reseller.customer-orders.hosting.store') }}" class="bg-white dark:bg-slate-900 rounded-2xl border border-slate-200 dark:border-slate-800 p-6 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium mb-2">Customer</label>
            <select name="customer_id" required class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                @foreach ($customers as $customer)
                    <option value="{{ $customer->id }}" @selected(old('customer_id', $selectedCustomer?->id) == $customer->id)>{{ $customer->name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Your catalog product</label>
            <select name="reseller_product_id" required class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected(old('reseller_product_id') == $product->id)>
                        {{ $product->name }}
                        @if($product->adminProduct) (provisions) @else (invoice only) @endif
                    </option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Billing cycle</label>
            <select name="billing_cycle" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                @foreach (['monthly', 'quarterly', 'semi-annual', 'annual'] as $cycle)
                    <option value="{{ $cycle }}" @selected(old('billing_cycle', 'monthly') === $cycle)>{{ ucfirst($cycle) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Order type</label>
            <select name="order_type" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
                <option value="provision" @selected(old('order_type', 'provision') === 'provision')>Full order (service + invoice)</option>
                <option value="invoice_only" @selected(old('order_type') === 'invoice_only')>Invoice only (no service)</option>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Due date</label>
            <input type="date" name="due_date" value="{{ old('due_date', now()->addDays(7)->format('Y-m-d')) }}" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">
        </div>

        <div>
            <label class="block text-sm font-medium mb-2">Notes</label>
            <textarea name="notes" rows="2" class="w-full px-4 py-2 border rounded-lg bg-white dark:bg-slate-800">{{ old('notes') }}</textarea>
        </div>

        <button type="submit" class="px-6 py-2.5 bg-purple-600 hover:bg-purple-700 text-white rounded-lg font-medium">Create order</button>
    </form>
</div>
@endsection
